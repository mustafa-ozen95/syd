<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SettingsRequest;
use App\Services\ActivityRecorder;
use App\Services\Settings\SettingsCatalog;
use App\Services\Settings\SettingsRepository;
use App\Services\Settings\SettingsTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Ayarlar — yalnızca yönetici.
 *
 * Gruplar sekmedir; her grup kendi alanlarını katalogdan çizer. Değer veritabanında durur ve
 * config'in ÜZERİNE BİNDİRİLİR; satırı olmayan ayar varsayılanında kalır.
 *
 * Yetkilendirme: HasMiddleware ile `can:admin`.
 */
class AyarController extends Controller implements HasMiddleware
{
    public function __construct(
        private SettingsCatalog $katalog,
        private SettingsRepository $ayarlar,
        private SettingsTabs $sekmeler,
        private ActivityRecorder $kayit,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:admin')];
    }

    public function index(Request $request): View
    {
        $grup = (string) $request->query('grup', '');
        $grup = in_array($grup, $this->katalog->groupKeys(), true)
            ? $grup
            : $this->katalog->groupKeys()[0];

        return view('settings.index', [
            'group' => $grup,
            'definition' => $this->katalog->group($grup),
            'settings' => $this->katalog->definitionsFor($grup),
            'values' => collect($this->katalog->definitionsFor($grup))
                ->mapWithKeys(fn ($tanim): array => [$tanim->key => $this->ekranDegeri($tanim->key)])
                ->all(),
            'tabs' => $this->sekmeler->all($grup),
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $degisenler = $this->ayarlar->put($request->payload(), $request->user());

        /*
         * NE DEĞİŞTİĞİ yazılır, yeni hâli değil. "Ayarlar güncellendi" satırı
         * denetimde hiçbir soruyu yanıtlamıyor; bir ayarın ne zaman kimin
         * eliyle gevşediği tam olarak bu satırdan okunur.
         */
        if ($degisenler !== []) {
            $ozet = collect($degisenler)
                ->map(fn (array $d, string $anahtar): string => sprintf(
                    '%s: %s → %s',
                    $anahtar,
                    $this->metin($d['from']),
                    $this->metin($d['to']),
                ))
                ->implode(' · ');

            $this->kayit->kaydet('ayar_degisti', $ozet);
        }

        return redirect()
            ->route('admin.ayarlar.index', ['grup' => $request->group()])
            ->with('status', $degisenler === [] ? 'Değişiklik yok.' : 'Ayarlar kaydedildi.');
    }

    /**
     * Ekranda gösterilecek değer.
     *
     * Dizi tutan bir ayar forma virgüllü metin olarak iner; geri çevrimi
     * okuyan taraf yapar. Diziyi ekranda dizi gibi göstermek, kullanıcıya
     * PHP söz dizimi yazdırmak olurdu.
     */
    private function ekranDegeri(string $anahtar): mixed
    {
        $deger = $this->ayarlar->current($anahtar);

        return is_array($deger) ? implode(',', $deger) : $deger;
    }

    private function metin(mixed $deger): string
    {
        if (is_bool($deger)) {
            return $deger ? 'açık' : 'kapalı';
        }

        return is_array($deger) ? implode(',', $deger) : (string) $deger;
    }
}
