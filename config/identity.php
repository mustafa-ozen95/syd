<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Kimlik Yönetim Merkezi
|--------------------------------------------------------------------------
| Bu panel kimliği MERKEZDEN alır: kullanıcı merkezin kendi sayfasında giriş
| yapar, parola buraya hiç uğramaz. Kardeş panellerde aynı dosya aynı
| anahtarlarla duruyor; biri değişirse ötekilere de bakın.
|
| YEREL GİRİŞ KAPANMAZ. Merkez erişilemez olduğunda panel kilitlenmemeli;
| e-posta/parola ile giriş yerinde kalır.
*/

return [
    /*
     * Merkezle giriş açık mı? Kapalıyken uçlar 404 döner ve giriş ekranında
     * düğme hiç çizilmez — "var ama çalışmıyor" bir düğme göstermeyiz.
     */
    'acik' => env('IDENTITY_ENABLED', false),

    /* İstemci kimliği ve sırrı verilmemişse akış zaten kurulamaz. */
    'configured' => filled(env('IDENTITY_CLIENT_ID')) && filled(env('IDENTITY_CLIENT_SECRET')),

    'url' => rtrim((string) env('IDENTITY_URL', 'https://kimlik.ornek.test'), '/'),
    'client_id' => env('IDENTITY_CLIENT_ID'),
    'client_secret' => env('IDENTITY_CLIENT_SECRET'),

    /*
     * Dönüş adresi MERKEZDEKİ KAYITLA birebir aynı olmalı; tek harf farkı
     * akışı sessizce düşürür. Merkez yalnızca https kabul ediyor.
     */
    'redirect_uri' => env('IDENTITY_REDIRECT_URI', 'https://syd.ornek.test/login/center/callback'),

    'name' => env('IDENTITY_NAME', 'Kimlik Yönetim Merkezi'),

    /*
     * OTOMATİK HESAP AÇMA KARARI BURADA DEĞİL.
     *
     * Hangi uygulamada hesabın kendiliğinden açılacağına MERKEZ karar verir
     * (İstemciler ekranında uygulama başına) ve kimlik yanıtında söyler.
     * Panelde ikinci bir anahtar tutmak, aynı kararı iki yere yazmak olurdu.
     */

    /*
     * BİLDİRİM SIRRI.
     *
     * Merkez, rol ya da durum değişince bu panele ANINDA haber verir ve
     * bildirimi bu sırla imzalar (merkezdeki İstemciler ekranında yazar).
     *
     * OAuth istemci sırrı BU İŞ İÇİN KULLANILAMAZ: merkezde bcrypt özeti
     * olarak duruyor, merkez onu doğrulayabilir ama üretemez.
     *
     * Boş bırakılırsa bildirim KABUL EDİLMEZ: açık oturumdaki kişi
     * değişikliği ara katmanın yoklamasında (`dogrulama_dakika`), oturumu
     * olmayan hesap gecelik eşitleme turunda (identity:sync) öğrenir.
     */
    'notify_secret' => env('IDENTITY_NOTIFY_SECRET'),

    /*
     * MERKEZDEKİ DURUM NE SIKLIKLA SORULSUN? (dakika)
     *
     * Ara katman her istekte sormaz; yanıt kullanıcı başına bu kadar süre
     * önbelleklenir. Sayı, "erişimi kalkan biri en fazla ne kadar daha
     * içeride kalabilir" sorusunun yanıtıdır.
     */
    'dogrulama_dakika' => (int) env('IDENTITY_VERIFY_MINUTES', 5),

    /*
     * YÖNETİCİLİK DEVROLUR MU? — kapalı.
     *
     * Merkezin yöneticisi kimlikleri yönetir; buranın yöneticisi bütün
     * bütün yedeklere ve kullanıcılara dokunur. İkisi kendiliğinden aynı kişi
     * olmamalı: merkezde rol dağıtabilen biri, buradaki hiçbir yöneticinin
     * haberi olmadan kendini burada da yönetici yapabilirdi.
     */
    'yonetici_devri' => env('IDENTITY_ADMIN_TAKEOVER', false),

    /* Merkezin sertifikası özelse kök sertifikanın yolu. */
    'ca_cert' => env('IDENTITY_CA_CERT'),
];
