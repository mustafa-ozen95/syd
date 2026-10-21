<?php

declare(strict_types=1);

namespace App\ConsoleModule\Commands;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Remote\RemoteError;
use App\ConsoleModule\Remote\SitePc;
use App\Services\ActivityRecorder;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Console\Command;

/**
 * ANLIK AJAN — dakikada bir: ajan iş açılınca kurulur, iş bitince
 * kaldırılır.
 *
 *   PC'de açık iş var, ajan yok      → kurulur
 *   PC'de açık iş yok, ajan kurulu   → kaldırılır (son iş kapandıktan
 *                                      BEKLEME süresi sonra: ajan son adımı
 *                                      bildirebilsin)
 *
 * Kuyruk yerine eşitleme: bir deneme düşerse sonraki dakika yeniden dener;
 * panel yeniden başlasa da durum kendiliğinden toparlanır.
 */
class SyncAgentsCommand extends Command
{
    protected $signature = 'konsol:ajanlar';

    protected $description = 'Konsol PC\'lerinde ajanı açık işlere göre kurar ya da kaldırır';

    private const BEKLEME_SANIYE = 60;

    public function handle(SitePc $uzak, ActivityRecorder $kayit, UserPermissionResolver $yetki): int
    {
        foreach (ConsolePc::query()->orderBy('id')->get() as $pc) {
            $acik = $pc->isler()->acik()->orderBy('id')->get();

            if ($acik->isNotEmpty() && ! $pc->ajanKuruluMu()) {
                $this->kur($pc, $acik->first(), $uzak, $kayit, $yetki);
            } elseif ($acik->isEmpty() && $pc->ajanKuruluMu() && $this->sonIsSoguduMu($pc)) {
                $this->kaldir($pc, $uzak, $kayit);
            }
        }

        return self::SUCCESS;
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

        try {
            $metin = $uzak->kur($pc, $kim);
        } catch (RemoteError $e) {
            $this->sonuc($pc, $ilk, 'Ajan kurulamadı (bir dakika sonra yeniden denenecek): '.$e->getMessage(), true);

            return;
        }

        $this->sonuc($pc, null, $metin, false);
        $kayit->kaydet('konsol_pc_kur', "{$pc->ad}: ajan iş için kuruldu", $pc, $kim);

        foreach ($pc->isler()->acik()->get() as $is) {
            $is->olaylar()->create(['asama' => 'bilgi', 'mesaj' => "Ajan {$pc->ad} üzerinde kuruldu.", 'kaynak' => 'Panel']);
        }
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
