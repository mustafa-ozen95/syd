<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
| Panelin dışarıya açtığı okuma arayüzü. Kimlik doğrulaması jetonla yapılır
| ("Authorization: Bearer ..."); oturum çerezi kabul edilmez.
|
| Jeton bir KİMLİK BİLGİSİDİR ve sahibi adına konuşur. İki sınırı vardır ve
| ikisi birden geçerlidir:
|
|   1. Jetonun kendi yetkileri (hangi kaynakta ne yapabilir).
|   2. SAHİBİNİN yetkileri. Jeton sahibini aşamaz — sahibinin yetkisi
|      daraltılınca jeton da daralır.
|
| Bu yüzden jeton üretmek yöneticiye özeldir: başkası adına jeton çıkarmak,
| o kişinin yetkisini kalıcı bir anahtara dönüştürmektir.
*/

return [

    /*
    | Jetonun görünen öneki. Sızan bir dizgenin ne olduğu ilk bakışta
    | anlaşılsın; sır değildir, kayıtta ve ekranda basılabilir.
    */
    'prefix' => env('API_TOKEN_PREFIX', 'syd'),

    /*
    | Jetonun sır kısmının bayt uzunluğu. 32 bayt = 256 bit; base62'ye
    | çevrildiğinde ~43 karakter.
    */
    'secret_bytes' => (int) env('API_TOKEN_BYTES', 32),

    /*
    | Yeni jetonun varsayılan ömrü (gün). Boş bırakılırsa süresiz olur;
    | süresiz jeton unutulur ve unutulan jeton sızar.
    */
    'default_lifetime_days' => (int) env('API_TOKEN_LIFETIME_DAYS', 365),

    /*
    | Dakikada izin verilen istek sayısı (jeton başına). Sınır jetondadır,
    | IP'de değil: aynı IP'den gelen iki jeton birbirini yavaşlatmamalı.
    */
    'rate_limit' => (int) env('API_RATE_LIMIT', 120),

    /*
    | Kimliği doğrulanamayan istekler için dakikadaki sınır (adres başına).
    | Geçerli jetonlu istekler de bu kapıdan geçtiği için jeton sınırından
    | belirgin biçimde yüksektir; amaç kimliksiz bir uca sınırsız istek
    | atılabilmesini engellemektir.
    */
    'guest_rate_limit' => (int) env('API_GUEST_RATE_LIMIT', 600),

    /*
    |--------------------------------------------------------------------------
    | Uçların belgesi
    |--------------------------------------------------------------------------
    |
    | Anahtar rota adıdır ("api.v1." öneki olmadan). Liste TEK YERDE: hem jeton
    | ekranındaki belge sayfası hem de yetki etiketleri buradan çiziliyor.
    |
    | "ability" ucun istediği yetkidir ve "kaynak:aksiyon" biçimindedir
    | (bkz. config/permissions.php). Jeton bunu taşımak zorunda; SAHİBİ de aynı
    | yetkiye sahip olmalı.
    |
    | Belgesiz bir uç eklendiğinde ya da burada kalan bir girdinin rotası
    | silindiğinde test kırılır (bkz. ApiDocumentationTest).
    |
    */
    'endpoints' => [
        'devices.index' => [
            'summary' => 'Cihazlar',
            'ability' => 'yedek:view',
            'description' => 'Yedeği tutulan cihaz klasörleri: yedek sayısı, son yedek zamanı, toplam boyut.',
            'parameters' => [
                'search' => 'klasör adında arar',
            ],
        ],
        'devices.backups' => [
            'summary' => 'Cihazın yedekleri',
            'ability' => 'yedek:view',
            'description' => 'Bir cihaz klasöründeki yedek dosyaları, yeniden eskiye. Dosyanın İÇERİĞİ DÖNMEZ.',
            'parameters' => [
                'search' => 'dosya adında arar',
            ],
        ],
        'devices.download' => [
            'summary' => 'Yedeği indir',
            'ability' => 'yedek:download',
            'description' => 'AYRI YETKİ ister: dosya cihazın bütün yapılandırmasıdır. '
                .'Her indirme denetim kaydına, jetonun adıyla birlikte yazılır.',
        ],
        'junos.index' => [
            'summary' => 'Junos görüntüleri',
            'ability' => 'junos:view',
            'description' => 'Model, sürüm, dosya ve hazır kurulum komutu.',
        ],
    ],
];
