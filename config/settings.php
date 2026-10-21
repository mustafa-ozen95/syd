<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Panelden değiştirilebilen ayarlar
|--------------------------------------------------------------------------
|
| Anahtar, ÜZERİNE BİNDİRİLEN config yoludur ("session.lifetime"); değer
| veritabanında durur ve satırı olmayan ayar VARSAYILANINDA kalır. Böylece
| config dosyaları her zaman geçerli bir taban olur ve veritabanı yalnızca
| farkları taşır.
|
| BURAYA HER AYAR KONMAZ. Sırlar (API anahtarları, istemci sırrı, veritabanı
| parolası) kurulumun ortamında (.env ya da platformun gizli değişkenleri) kalır: panelden okunabilir hâle getirmek, yönetici
| hesabını ele geçiren birine onları da vermek olurdu. Buraya yalnızca
| DAVRANIŞ ayarları girer.
|
| Kardeş panellerde aynı yapı var; gruplar panele göre
| değişir.
|
*/

return [

    'groups' => [

        'panel' => [
            'label' => 'Panel',
            'description' => 'Saat dilimi ve genel davranış.',

            'settings' => [
                'app.timezone' => [
                    'label' => 'Saat dilimi',
                    'hint' => 'Kayıt ve denetim zamanları bu dilimde gösterilir.',
                    'type' => 'select',
                    'options' => [
                        'Europe/Istanbul' => 'Europe/Istanbul',
                        'UTC' => 'UTC',
                    ],
                    'rules' => ['required', 'timezone'],
                ],
            ],
        ],

        'guvenlik' => [
            'label' => 'Güvenlik',
            'description' => 'Oturum ve parola kuralları.',

            'settings' => [
                'session.lifetime' => [
                    'label' => 'Oturum süresi',
                    'hint' => 'HAREKETSİZLİK süresidir: sayaç her istekte sıfırlanır. '
                        .'Bu panelde hatırlama çerezi verilmiyor, yani süre gerçekten süre.',
                    'unit' => 'dakika',
                    'type' => 'int',
                    'rules' => ['integer', 'min:5', 'max:1440'],
                ],
            ],
        ],

        'kimlik' => [
            'label' => 'Kimlik Yönetim Merkezi',
            'description' => 'Merkezle giriş. HANGİ ROLÜN GİREBİLECEĞİ ve hesabın '
                .'kendiliğinden açılıp açılmayacağı BURADA DEĞİL, merkezdeki '
                .'İstemciler ekranındadır — erişim kararı doğası gereği merkezin '
                .'işidir. İstemci kimliği ve sırrı kurulumun ortam değişkenlerindedir.',

            'settings' => [
                'kimlik.acik' => [
                    'label' => 'Merkezle giriş',
                    'hint' => 'Kapalıyken giriş ekranında düğme çizilmez ve uçlar 404 döner. '
                        .'Panelin kendi parola girişi her hâlükârda açık kalır.',
                    'type' => 'bool',
                    'rules' => ['boolean'],
                ],

                'kimlik.yonetici_devri' => [
                    'label' => 'Merkezdeki yönetici burada da yönetici olsun',
                    'hint' => 'KAPALI TUTUN. Açıkken merkezde rol dağıtabilen biri, buradaki '
                        .'hiçbir yöneticinin haberi olmadan kendini burada da yönetici yapabilir.',
                    'type' => 'bool',
                    'rules' => ['boolean'],
                ],

                'kimlik.dogrulama_dakika' => [
                    'label' => 'Merkeze sorma aralığı',
                    'hint' => '"Erişimi kalkan biri en fazla ne kadar daha içeride kalabilir" '
                        .'sorusunun yanıtı. Her istekte sorulmaz; yanıt kullanıcı başına '
                        .'bu süre kadar önbelleklenir.',
                    'unit' => 'dakika',
                    'type' => 'int',
                    'rules' => ['integer', 'min:1', 'max:60'],
                ],
            ],
        ],
    ],
];
