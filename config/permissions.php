<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Yetki sözlüğü
|--------------------------------------------------------------------------
|
| Panelin tanıdığı kaynaklar ve her kaynakta yapılabilecek işler. Yetki
| etiketleri HEP buradan çiziliyor; ikinci bir liste yoktur — olsaydı biri
| değişince öbürü sessizce eski kalırdı.
|
| Her "kaynak:aksiyon" çifti bir Gate'tir (bkz. AppServiceProvider). Rol
| ekranı, kullanıcı istisnaları ve API jetonları aynı listeyi kullanır.
|
| Kardeş panellerdeki yapının aynısı: rol canlı okunur, kişi yalnız
| istisna tutar (bkz. UserPermissionResolver).
|
*/

return [

    'actions' => [
        'view' => 'Görüntüle',
        'download' => 'İndir',
        'create' => 'Ekle',
        'update' => 'Düzenle',
        'delete' => 'Sil',
    ],

    /*
     * 'view' olmadan başka hiçbir aksiyon geçmez: listeyi göremeyen birinin
     * dosya indirmesi ya da kayıt eklemesi anlamsız (bkz. resolver).
     */
    'requires_view' => true,

    'resources' => [
        /*
         * YEDEKLER. Yazma yok — yedekleri cihazlar FTP ile bırakıyor, panel
         * yalnızca okuyor. İndirmek AYRI yetkidir: liste dosya adlarını
         * gösterir, dosyanın kendisi cihazın bütün yapılandırmasıdır
         * (parola özetleri dahil).
         */
        'yedek' => [
            'label' => 'Yedekler',
            'actions' => ['view', 'download'],
        ],

        /* Kurulum sayfasındaki Junos görüntüleri (bkz. JunosImage). */
        'junos' => [
            'label' => 'Junos görüntüleri',
            'actions' => ['view', 'create', 'update', 'delete'],
        ],
    ],
];
