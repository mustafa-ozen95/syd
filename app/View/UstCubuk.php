<?php

declare(strict_types=1);

namespace App\View;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Üst çubuğun bölüm listesi.
 *
 * Liste TEK YERDE ve görünürlük YETKİYE bağlı: göremeyeceği bölümün
 * bağlantısı 403 üretmekten başka işe yaramaz. Hesap burada yapılır;
 * şablon yalnız hazır listeyi çizer.
 */
class UstCubuk
{
    public function compose(View $view): void
    {
        $kullanici = Auth::user();

        $bolumler = [
            [Gate::allows('yedek:view'), 'yedek.index', 'Yedekler', ['yedek.*']],
            [Gate::allows('junos:view'), 'kurulum.index', 'Kurulum', ['kurulum.*']],
            [Gate::allows('konsol:view'), 'konsol.index', 'Konsol', ['konsol.*']],
            [Gate::allows('admin'), 'admin.kullanicilar.index', 'Kullanıcılar', ['admin.kullanicilar.*']],
            [Gate::allows('admin'), 'admin.kayitlar.denetim', 'Kayıtlar', ['admin.kayitlar.*']],
            /* Roller, API ve Sertifika Ayarlar'ın sekmeleri; şeritte ayrı girdileri yok. */
            [Gate::allows('admin'), 'admin.ayarlar.index', 'Ayarlar',
                ['admin.ayarlar.*', 'admin.sertifika.*', 'admin.roller.*', 'admin.api.*']],
        ];

        $view->with([
            'kullanici' => $kullanici,
            'bolumler' => $kullanici === null ? [] : array_values(array_map(fn (array $b): array => [
                'url' => route($b[1]),
                'label' => $b[2],
                'active' => request()->routeIs(...$b[3]),
            ], array_filter($bolumler, fn (array $b): bool => $b[0]))),
        ]);
    }
}
