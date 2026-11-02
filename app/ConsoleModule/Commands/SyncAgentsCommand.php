<?php

declare(strict_types=1);

namespace App\ConsoleModule\Commands;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Remote\RemoteError;
use App\ConsoleModule\Remote\SitePc;
use App\Models\ApiToken;
use App\Services\ActivityRecorder;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * ANLIK AJAN — dakikada bir: ajan iş açılınca kurulur, iş bitince
 * kaldırılır.
 *
 *   PC'de açık iş var, ajan yok      → kurulur
 *   PC'de açık iş var, ajan sessiz   → yeniden kurulur (jeton süresi dolmuş,
 *                                      PC formatlanmış, görev silinmiş: ajan
 *                                      panele hiç gelmiyorsa ölüdür)
 *   PC'de açık iş yok, ajan kurulu   → kaldırılır (son iş kapandıktan
 *                                      BEKLEME süresi sonra: ajan son adımı
 *                                      bildirebilsin)
 *   Açık iş uzun süredir sessiz      → "hata" ile kapanır; yoksa sonsuza dek
 *                                      "Ajan bekleniyor"da kalırdı
 *
 * Kuyruk yerine eşitleme: bir deneme düşerse yeniden dener; panel yeniden
 * başlasa da durum kendiliğinden toparlanır. Düşen kurulum giderek seyrek
 * denenir ve PC'ye ulaşılamıyorsa jeton ÜRETİLMEZ: kapalı bir PC için her
 * dakika bir jeton üretilip iptal ediliyordu.
 */
class SyncAgentsCommand extends Command
{
    protected $signature = 'console:agents';

    protected $description = 'Konsol PC\'lerinde ajanı açık işlere göre kurar ya da kaldırır';

    private const BEKLEME_SANIYE = 60;

    public function handle(SitePc $uzak, ActivityRecorder $kayit, UserPermissionResolver $yetki): int
    {
        $this->sessizIsleriKapat($kayit);
        $this->geciciGoruntuleriSil();
        $this->eskiAjanJetonlariniSil();

        foreach (ConsolePc::query()->with('jeton')->orderBy('id')->get() as $pc) {
            $acik = $pc->isler()->acik()->orderBy('id')->get();

            if ($acik->isNotEmpty() && (! $pc->ajanKuruluMu() || $this->ajanOluMu($pc))) {
                if ($this->denemeZamaniMi($pc)) {
                    $this->kur($pc, $acik->first(), $uzak, $kayit, $yetki);
                }
            } elseif ($acik->isEmpty() && $pc->ajanKuruluMu() && $this->sonIsSoguduMu($pc)) {
                $this->kaldir($pc, $uzak, $kayit);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Ajan her birkaç saniyede panele gelir (iş yoklaması, çıktı); jetonu
     * dakikalardır kullanılmıyorsa ya da geçersizse ajan çalışmıyordur.
     */
    private function ajanOluMu(ConsolePc $pc): bool
    {
        $esik = now()->subMinutes((int) config('console.agent_stale_minutes'));
        $jeton = $pc->jeton;

        if ($jeton === null || ! $jeton->isUsable()) {
            return true;
        }

        return ($jeton->last_used_at ?? $jeton->created_at) < $esik && ($pc->son_kurulum_at === null || $pc->son_kurulum_at < $esik);
    }

    /**
     * Bilgisayardan seçilip yalnız bir iş için TFTP dizinine konan Junos
     * dosyaları iş kapanınca silinir (bekleme süresi sonra: TFTP son paketi
     * vermiş olsun). Dosya başka bir açık iş tarafından kullanılmıyorsa.
     */
    private function geciciGoruntuleriSil(): void
    {
        $kapali = ConsoleJob::query()->whereNotIn('durum', ConsoleJob::ACIK)->whereNotNull('gecici_goruntu')
            ->where('updated_at', '<', now()->subSeconds(self::BEKLEME_SANIYE))->get();

        foreach ($kapali as $is) {
            if (! ConsoleJob::query()->acik()->where('gecici_goruntu', $is->gecici_goruntu)->exists()) {
                Storage::disk('junos')->delete((string) $is->gecici_goruntu);
            }
            $is->forceFill(['gecici_goruntu' => null])->saveQuietly();
        }
    }

    /**
     * Ajan jetonu her kurulumda üretilir, ajan kaldırılınca iptal edilir.
     * İptal edilmiş AJAN jetonları bir süre (kayıt için) durur, sonra silinir:
     * Ayarlar → Jetonlar'da yüzlerce ölü satır birikmesin. Elle açılan API
     * jetonlarına dokunulmaz.
     */
    private function eskiAjanJetonlariniSil(): void
    {
        ApiToken::query()->where('name', 'like', SitePc::JETON_ONEKI.'%')->whereNotNull('revoked_at')
            ->where('revoked_at', '<', now()->subDays((int) config('console.agent_token_keep_days')))
            ->delete();
    }

    private function denemeZamaniMi(ConsolePc $pc): bool
    {
        return $pc->ajan_sonraki_deneme_at === null || $pc->ajan_sonraki_deneme_at->isPast();
    }

    /** Uzun süredir haber gelmeyen açık iş kapanır; yerine yenisi açılabilsin. */
    private function sessizIsleriKapat(ActivityRecorder $kayit): void
    {
        $dakika = (int) config('console.job_idle_minutes');

        foreach (ConsoleJob::query()->acik()->where('updated_at', '<', now()->subMinutes($dakika))->get() as $is) {
            $mesaj = "Zaman aşımı: {$dakika} dakikadır ajandan haber yok. Cihazın durumunu konsoldan kontrol edip gerekirse işi yeniden açın.";
            $is->olaylar()->create(['asama' => 'hata', 'mesaj' => $mesaj, 'kaynak' => 'Panel']);
            $is->kapat('hata', $mesaj);
            $kayit->kaydet('konsol_is_hata', "Konsol işi zaman aşımıyla kapandı: {$is->ad} · {$is->islemOzeti()}", $is);
            $this->warn("{$is->ad}: {$mesaj}");
        }
    }

    private function sonIsSoguduMu(ConsolePc $pc): bool
    {
        $son = $pc->isler()->max('updated_at');

        return $son === null || now()->diffInSeconds($son, true) >= self::BEKLEME_SANIYE;
    }

    private function kur(ConsolePc $pc, ConsoleJob $ilk, SitePc $uzak, ActivityRecorder $kayit, UserPermissionResolver $yetki): void
    {
        /* Jeton işi açanın adına üretilir; onun ajan yetkisi yoksa kurulmaz. */
        $kim = $ilk->olusturan;

        if ($kim === null || ! $yetki->allows($kim, 'konsol', 'ajan')) {
            $this->sonuc($pc, $ilk, 'Ajan kurulamadı: işi açanın "Ajan olarak bağlan" yetkisi yok.', true);

            return;
        }

        if ($pc->ajanKuruluMu()) {
            $this->sonuc($pc, $ilk, 'Ajan panele gelmiyor (çalışmıyor ya da jetonu geçersiz); yeniden kuruluyor.', true);
        }

        try {
            /* Önce bağlantı: PC'ye ulaşılamıyorsa jeton üretmenin anlamı yok. */
            $uzak->sina($pc);
            $metin = $uzak->kur($pc, $kim);
        } catch (RemoteError $e) {
            $dakika = $this->ertele($pc);
            $this->sonuc($pc, $ilk, "Ajan kurulamadı ({$dakika} dakika sonra yeniden denenecek): ".$e->getMessage(), true);

            return;
        }

        $pc->forceFill(['ajan_deneme_sayisi' => 0, 'ajan_sonraki_deneme_at' => null])->save();
        $this->sonuc($pc, null, $metin, false);
        $kayit->kaydet('konsol_pc_kur', "{$pc->ad}: ajan iş için kuruldu", $pc, $kim);

        foreach ($pc->isler()->acik()->get() as $is) {
            $is->olaylar()->create(['asama' => 'bilgi', 'mesaj' => "Ajan {$pc->ad} üzerinde kuruldu.", 'kaynak' => 'Panel']);
        }
    }

    /** Düşen kurulum giderek seyrek denenir: 1, 2, 4, 8, en çok 15 dakika. */
    private function ertele(ConsolePc $pc): int
    {
        $sayi = $pc->ajan_deneme_sayisi + 1;
        $dakika = min(2 ** ($sayi - 1), (int) config('console.agent_retry_max_minutes'));
        $pc->forceFill(['ajan_deneme_sayisi' => $sayi, 'ajan_sonraki_deneme_at' => now()->addMinutes($dakika)])->save();

        return $dakika;
    }

    private function kaldir(ConsolePc $pc, SitePc $uzak, ActivityRecorder $kayit): void
    {
        try {
            $metin = $uzak->kaldir($pc);
        } catch (RemoteError $e) {
            $this->sonuc($pc, null, 'Ajan kaldırılamadı (bir dakika sonra yeniden denenecek): '.$e->getMessage(), true);

            return;
        }

        $this->sonuc($pc, null, $metin, false);
        $kayit->kaydet('konsol_pc_kaldir', "{$pc->ad}: açık iş kalmadı, ajan kaldırıldı", $pc);
    }

    /** Aynı hata her dakika işin çizelgesine yazılmasın: yalnız değişince. */
    private function sonuc(ConsolePc $pc, ?ConsoleJob $is, string $mesaj, bool $hata): void
    {
        $mesaj = mb_substr($mesaj, 0, 500);

        if ($is !== null && $pc->ajan_mesaj !== $mesaj) {
            $is->olaylar()->create(['asama' => 'bilgi', 'mesaj' => $mesaj, 'kaynak' => 'Panel']);
            $is->update(['son_mesaj' => mb_substr($mesaj, 0, 500)]);
        }

        $pc->forceFill(['ajan_mesaj' => $mesaj])->save();
        $hata ? $this->warn("{$pc->ad}: {$mesaj}") : $this->info("{$pc->ad}: {$mesaj}");
    }
}
