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
    'acik' => env('KIMLIK_ACIK', false),

    /* İstemci kimliği ve sırrı verilmemişse akış zaten kurulamaz. */
    'yapilandirildi' => filled(env('KIMLIK_ISTEMCI')) && filled(env('KIMLIK_ISTEMCI_SIR')),

    'adres' => rtrim((string) env('KIMLIK_ADRES', 'https://kimlik.ornek.test'), '/'),
    'istemci' => env('KIMLIK_ISTEMCI'),
    'sir' => env('KIMLIK_ISTEMCI_SIR'),

    /*
     * Dönüş adresi MERKEZDEKİ KAYITLA birebir aynı olmalı; tek harf farkı
     * akışı sessizce düşürür. Merkez yalnızca https kabul ediyor.
     */
    'donus' => env('KIMLIK_DONUS', 'https://syd.ornek.test/giris/merkez/geri'),

    'ad' => env('KIMLIK_AD', 'Kimlik Yönetim Merkezi'),

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
     * olmayan hesap gecelik eşitleme turunda (kimlik:esitle) öğrenir.
     */
    'bildirim_sirri' => env('KIMLIK_BILDIRIM_SIR'),

    /*
     * MERKEZDEKİ DURUM NE SIKLIKLA SORULSUN? (dakika)
     *
     * Ara katman her istekte sormaz; yanıt kullanıcı başına bu kadar süre
     * önbelleklenir. Sayı, "erişimi kalkan biri en fazla ne kadar daha
     * içeride kalabilir" sorusunun yanıtıdır.
     */
    'dogrulama_dakika' => (int) env('KIMLIK_DOGRULAMA_DAKIKA', 5),

    /*
     * YÖNETİCİLİK DEVROLUR MU? — kapalı.
     *
     * Merkezin yöneticisi kimlikleri yönetir; buranın yöneticisi bütün
     * bütün yedeklere ve kullanıcılara dokunur. İkisi kendiliğinden aynı kişi
     * olmamalı: merkezde rol dağıtabilen biri, buradaki hiçbir yöneticinin
     * haberi olmadan kendini burada da yönetici yapabilirdi.
     */
    'yonetici_devri' => env('KIMLIK_YONETICI_DEVRI', false),

    /* Merkezin sertifikası özelse kök sertifikanın yolu. */
    'kok' => env('KIMLIK_KOK'),
];
