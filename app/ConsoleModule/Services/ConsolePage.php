<?php

declare(strict_types=1);

namespace App\ConsoleModule\Services;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Contracts\ImageSource;
use App\ConsoleModule\Models\ConsoleEvent;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsoleOutput;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Sources\TftpProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Konsol ekranlarının bütün verisi. Şablon hesap yapmaz; burada hazırlanan
 * satırları çizer.
 */
class ConsolePage
{
    private const LISTE_SINIRI = 100;

    /** Canlı ekranın bir yoklamada en çok aldığı parça. */
    private const AKIS_PARCA = 500;

    public function __construct(
        private ImageSource $goruntuler,
        private ConfigurationSource $yedekler,
    ) {}

    /** @return array<string, mixed> */
    public function liste(): array
    {
        $yetki = $this->yetki();
        $form = (string) old('_form', '');

        return [
            'yetki' => $yetki,
            'satirlar' => $this->isSatirlari(),
            'pcSecenekleri' => ConsolePc::query()->orderBy('ad')->pluck('ad', 'id')->all(),
            /* PC değişince adres alanları onun kaydıyla dolar (console-form.js). */
            'pcAdresleri' => ConsolePc::query()->get(['id', 'cihaz_ip', 'gecit'])
                ->mapWithKeys(fn (ConsolePc $p): array => [$p->id => ['ip' => (string) $p->cihaz_ip, 'gecit' => (string) $p->gecit]])->all(),
            'adresTurleri' => ConsoleJob::ADRES_TURLERI,
            'goruntuSecenekleri' => $this->goruntuler->secenekler(),
            'yapilandirmaTurleri' => ConsoleJob::YAPILANDIRMA_TURLERI,
            'yedekCihazlari' => $yetki['ekle'] ? $this->yedekler->cihazlar() : [],
            'ekleAcik' => $form === 'ekle',
            'ekleDeger' => $ekleDeger = $this->isGirdisi($form === 'ekle'),
            /* Seçilen yedeğin listesi: hatayla dönen formda dolu gelsin; sonrası console-form.js. */
            'yedekDosyalari' => $yetki['ekle'] && $ekleDeger['yedek_cihaz'] !== '' ? $this->yedekSecenekleri($ekleDeger['yedek_cihaz']) : [],
            'platformlar' => array_map(fn (array $p): string => $p['etiket'], ConsolePc::PLATFORMLAR),
            'pcler' => $yetki['pc'] ? $this->pcSatirlari($form) : [],
            'pcYeniAcik' => $form === 'pc-yeni',
            'pcYeniDeger' => $this->pcGirdisi(null, $form === 'pc-yeni'),
            'pcSonuc' => session('pcSonuc'),
            'loaderKomutlari' => (array) config('konsol.loader_komutlari'),
            'kurulumKomutu' => (string) config('konsol.kurulum_komutu'),
            'tftp' => rtrim((string) config('junos.tftp'), '/'),
        ];
    }

    /** @return array<string, mixed> */
    public function is(ConsoleJob $is): array
    {
        $is->loadMissing('pc', 'olusturan');

        return [
            'is' => $is,
            'yetki' => $this->yetki(),
            'acik' => $is->acikMi(),
            'olusturan' => $is->olusturan?->name ?? '—',
            'acilis' => $is->created_at?->format('d.m.Y H:i'),
            'yapilandirma' => $is->yapilandirmaOzeti(),
            'kapatOnay' => "{$is->ad} işi kapatılacak. Ajan konsolu bırakır; cihazda o ana kadar yapılanlar (ör. biçimlendirme) geri alınmaz.",
            'silOnay' => "{$is->ad} işi, adımları ve konsol kaydı geçmişten silinecek.",
        ];
    }

    /**
     * Yoklama: `sonra` kimliğinden sonraki çıktı, işin durumu ve adımlar.
     *
     * @return array<string, mixed>
     */
    public function akis(ConsoleJob $is, int $sonra): array
    {
        $parcalar = $is->ciktilar()->where('id', '>', $sonra)->orderBy('id')->limit(self::AKIS_PARCA)->get(['id', 'metin']);

        return [
            'metin' => $parcalar->map(fn (ConsoleOutput $p): string => mb_scrub((string) $p->metin, 'UTF-8'))->implode(''),
            'son' => $parcalar->last()?->id ?? $sonra,
            'devami' => $parcalar->count() === self::AKIS_PARCA,
            'acik' => $is->acikMi(),
            'durum' => $is->durumEtiketi(),
            'renk' => $is->durumRengi()->value,
            'sonMesaj' => (string) $is->son_mesaj,
            'bekliyor' => $is->acikMi() && $is->asama === 'uyari',
            'ajanVar' => $is->olaylar()->where('kaynak', 'Ajan')->exists(),
            'ilerleme' => app(TftpProgress::class)->icin($is),
            /* En yenisi üstte. */
            'olaylar' => $is->olaylar()->orderByDesc('id')->get()->map(fn (ConsoleEvent $o): array => [
                'zaman' => $o->created_at?->format('H:i:s'),
                'asama' => ConsoleJob::ASAMALAR[$o->asama] ?? $o->asama,
                'uyari' => $o->asama === 'uyari',
                'hata' => $o->asama === 'hata',
                'mesaj' => (string) $o->mesaj,
                'kaynak' => (string) $o->kaynak,
            ])->all(),
        ];
    }

    /** @return array{ekle: bool, pc: bool, sil: bool} */
    private function yetki(): array
    {
        return [
            'ekle' => Gate::allows('konsol:create'),
            'pc' => Gate::allows('konsol:update'),
            'sil' => Gate::allows('konsol:delete'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function isSatirlari(): array
    {
        return ConsoleJob::query()
            ->with('pc')
            ->orderByRaw('CASE WHEN durum IN (?, ?) THEN 0 ELSE 1 END', ConsoleJob::ACIK)
            ->orderByDesc('id')
            ->limit(self::LISTE_SINIRI)
            ->get()
            ->map(fn (ConsoleJob $is): array => [
                'is' => $is,
                'pc' => $is->pc?->ad ?? '—',
                'goruntu' => (string) $is->goruntu_etiket,
                'yapilandirma' => $is->yapilandirmaOzeti(),
                'durum' => $is->durumEtiketi(),
                'renk' => $is->durumRengi(),
                'bekliyor' => $is->acikMi() && $is->asama === 'uyari',
                'guncellendi' => $is->updated_at?->format('d.m.Y H:i'),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function pcSatirlari(string $form): array
    {
        return ConsolePc::query()->with('jeton')->orderBy('ad')->get()->map(fn (ConsolePc $pc): array => [
            'pc' => $pc,
            'platform' => ConsolePc::PLATFORMLAR[$pc->platform]['etiket'] ?? $pc->platform,
            'seriPort' => $pc->seri_port ?: 'otomatik',
            'ajan' => $pc->ajanKuruluMu() ? 'Kurulu' : 'Kurulu değil',
            'ajanSon' => $pc->jeton?->last_used_at?->diffForHumans() ?? '—',
            'ajanMesaj' => $pc->ajan_mesaj,
            'acik' => $acik = $form === 'pc-'.$pc->id,
            'deger' => $this->pcGirdisi($pc, $acik),
            'silOnay' => "{$pc->ad} listeden, işleri ve konsol kayıtları geçmişten silinecek.",
        ])->all();
    }

    /** @return array<string, string> */
    private function pcGirdisi(?ConsolePc $pc, bool $hatali): array
    {
        $deger = [];

        foreach (['ad', 'platform', 'adres', 'gorunen_adres', 'port', 'kullanici', 'seri_port', 'cihaz_ip', 'gecit', 'dizin'] as $alan) {
            $deger[$alan] = $hatali ? (string) old($alan, '') : (string) ($pc?->{$alan} ?? '');
        }

        $deger['platform'] = $deger['platform'] ?: 'windows';

        return $deger;
    }

    /**
     * Cihazın yedekleri seçim kutusu için, en yenisi önce.
     *
     * @return list<array{dosya: string, etiket: string}>
     */
    public function yedekSecenekleri(string $cihaz): array
    {
        return array_map(fn (array $y): array => [
            'dosya' => $y['dosya'],
            'etiket' => $y['zaman'] === null ? $y['dosya'] : Carbon::createFromTimestamp($y['zaman'], config('app.timezone'))->format('d.m.Y H:i:s'),
        ], $this->yedekler->yedekler($cihaz));
    }

    /** @return array<string, string> */
    private function isGirdisi(bool $hatali): array
    {
        $deger = [];

        foreach (['pc_id', 'ad', 'goruntu', 'yapilandirma_turu', 'yedek_cihaz', 'yedek_dosya', 'adres_turu', 'cihaz_ip', 'gecit'] as $alan) {
            $deger[$alan] = $hatali ? (string) old($alan, '') : '';
        }

        /* Varsayılan: yükleme yok, fabrika ayarında kalır. */
        $deger['yapilandirma_turu'] = $deger['yapilandirma_turu'] ?: 'yok';
        $deger['adres_turu'] = $deger['adres_turu'] ?: 'sabit';

        /* Adres, seçili (ya da ilk) PC'nin kaydındakiyle dolu gelir. */
        $pc = ConsolePc::query()->find($deger['pc_id'] ?: null) ?? ConsolePc::query()->orderBy('ad')->first();
        $deger['cihaz_ip'] = $deger['cihaz_ip'] ?: (string) $pc?->cihaz_ip;
        $deger['gecit'] = $deger['gecit'] ?: (string) $pc?->gecit;

        return $deger;
    }
}
