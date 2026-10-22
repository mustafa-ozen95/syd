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
class TopBar
{
    public function compose(View $view): void
    {
        $kullanici = Auth::user();

        $bolumler = [
            [Gate::allows('yedek:view'), 'backup.index', 'Yedekler', ['backup.*']],
            [Gate::allows('junos:view'), 'setup.index', 'Kurulum', ['setup.*']],
            [Gate::allows('konsol:view'), 'console.index', 'Konsol', ['console.*']],
            [Gate::allows('admin'), 'admin.users.index', 'Kullanıcılar', ['admin.users.*']],
            [Gate::allows('admin'), 'admin.logs.audit', 'Kayıtlar', ['admin.kayitlar.*']],
            /* Roller, API ve Sertifika Ayarlar'ın sekmeleri; şeritte ayrı girdileri yok. */
            [Gate::allows('admin'), 'admin.settings.index', 'Ayarlar',
                ['admin.settings.*', 'admin.sertifika.*', 'admin.roles.*', 'admin.api.*']],
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
