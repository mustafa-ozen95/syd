<?php

declare(strict_types=1);

namespace App\Services\Settings;

use Illuminate\Support\Facades\Gate;

/**
 * Ayarlar şeridi.
 *
 * TEK LİSTE: ayar grupları ve sertifika aynı şeritte duruyor ve ikisi de
 * buradan besleniyor. Şerit her ekranda ayrı yazılsaydı biri değişince
 * diğeri sessizce eski kalır, kullanıcı da bir sekmeyi bir ekranda görüp
 * diğerinde göremezdi.
 *
 * Göremeyeceği sekme ŞERİTTE DE DURMAZ: erişemeyeceği bir bağlantı 403
 * üretmekten başka işe yaramaz.
 *
 * Kardeş panellerdeki sınıfın aynısı.
 */
class SettingsTabs
{
    public function __construct(private SettingsCatalog $catalog) {}

    /**
     * @return list<array{label: string, url: string, active: bool}>
     */
    public function all(?string $current = null): array
    {
        $tabs = [];

        foreach ($this->catalog->groups() as $key => $group) {
            $tabs[] = [
                'label' => $group['label'],
                'url' => route('admin.ayarlar.index', ['grup' => $key]),
                'active' => $key === $current,
            ];
        }

        /*
         * Sertifika da bu şeridin sekmesi: panelin adresine dair bir ayar ve
         * yılda birkaç kez bakılıyor — kendi başına bir bölüm olacak kadar
         * büyük değil.
         */
        if (Gate::allows('admin')) {
            /* Roller ve API jetonları da bu şeritte. */
            $tabs[] = [
                'label' => 'Roller',
                'url' => route('admin.roller.index'),
                'active' => $current === 'roller',
            ];
            $tabs[] = [
                'label' => 'API',
                'url' => route('admin.api.index'),
                'active' => $current === 'api',
            ];
            $tabs[] = [
                'label' => 'Sertifika',
                'url' => route('admin.sertifika.index'),
                'active' => $current === 'sertifika',
            ];
            $tabs[] = [
                'label' => 'Veritabanı',
                'url' => route('admin.veritabani.index'),
                'active' => $current === 'veritabani',
            ];
            $tabs[] = [
                'label' => 'Güncelleme',
                'url' => route('admin.guncelleme.index'),
                'active' => $current === 'guncelleme',
            ];
        }

        return $tabs;
    }
}
