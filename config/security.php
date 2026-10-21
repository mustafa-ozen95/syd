<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Güvenlik başlıkları
|--------------------------------------------------------------------------
| Her yanıta eklenir. Değerler burada tutulur ki hangi kısıtın neden konduğu
| tek yerden okunabilsin.
|
| YAZI TİPLERİ GOOGLE FONTS'TAN GELİR ve liste bunu yansıtmak ZORUNDA.
|
| Liste kaynağın geldiği yerle uyuşmazsa tarayıcı hem stil dosyasını hem
| font dosyalarını SESSİZCE ENGELLER: sayfa açılır, yalnızca yazı tipi
| sistem fontuna düşer ve sebebi hiçbir yerde görünmez.
|
| Kural: bir kaynağın nereden geldiği değişirse CSP de değişir. İkisi ayrı
| yerlerde yazılı ve biri değişince öbürü sessizce eski kalıyor.
*/

return [

    'headers' => [

        /*
        | Blade'de satır içi script ve style bırakılmadı, bu yüzden
        | 'unsafe-inline' gerekmiyor — CSP'nin asıl değeri de orada.
        */
        'Content-Security-Policy' => "default-src 'self'; "
            ."script-src 'self'; "
            ."style-src 'self' https://fonts.googleapis.com; "
            ."font-src 'self' https://fonts.gstatic.com; "
            ."img-src 'self' data:; "
            ."connect-src 'self'; "
            ."frame-src 'none'; "
            ."frame-ancestors 'self'; "
            ."base-uri 'self'; "
            /*
            | form-action'a merkez EKLENMEZ: merkeze gidiş bir yönlendirmedir
            | (302), form gönderimi değil. Dönüş de yine bu panele gelir.
            */
            ."form-action 'self'; "
            ."object-src 'none'",

        /*
        | Çerçeveleme korumasını asıl CSP'deki frame-ancestors üstleniyor;
        | bu, onu tanımayan eski tarayıcılar için.
        */
        'X-Frame-Options' => 'SAMEORIGIN',

        /* Tarayıcı içerik türünü tahmin etmesin. */
        'X-Content-Type-Options' => 'nosniff',

        /* Adres başka kökene taşınmaz. */
        'Referrer-Policy' => 'same-origin',

        'X-Permitted-Cross-Domain-Policies' => 'none',

        /*
        | HSTS KAPALI — ve BİLEREK max-age=0 GÖNDERİLİYOR.
        |
        | Başlık, tarayıcıya "bu adrese bir daha asla http ile gitme" der ve
        | bunu KENDİ İÇİNDE SAKLAR. Sertifika bir gün düşerse (yenileme elle
        | yapılıyorsa bu olasıdır) tarayıcı uyarı ekranını bile göstermiyor:
        | "Yine de devam et" düğmesi HSTS'li adreslerde YOKTUR. Yani sertifika
        | yenilenene kadar panele hiçbir şekilde ulaşılamaz.
        |
        | Başlığı SİLMEK YETMEZ: tarayıcıda zaten yerleşmiş kayıt bir yıl
        | daha yaşar. `max-age=0` o kaydı SİLDİRİR. Değer bu yüzden burada
        | duruyor; kaldırılırsa eski tarayıcılar kilitli kalır.
        |
        | Karşılığında ne kaybediliyor: ilk istek http ile yapılırsa araya
        | girme penceresi. http zaten https'e yönlendiriliyor; bu risk,
        | panele hiç girememe riskinden küçük sayıldı.
        */
        'Strict-Transport-Security' => 'max-age=0',

        /* Kullanılmayan tarayıcı yetenekleri kapatılır. */
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), '
            .'payment=(), usb=(), magnetometer=(), gyroscope=(), autoplay=()',

        'Cross-Origin-Opener-Policy' => 'same-origin',

        'Cross-Origin-Resource-Policy' => 'same-origin',
    ],
];
