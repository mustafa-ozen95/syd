<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Denetim kaydı
|--------------------------------------------------------------------------
| Kayıt zincirinin anahtarı ve önbellek anahtarları.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Değiştirilemezlik
    |--------------------------------------------------------------------------
    | Kayıt satırları HMAC-SHA256 ile zincirlenir: her satır kendinden
    | öncekinin özetini taşır. Bir satır değiştirilirse ya da silinirse ondan
    | sonraki bütün özetler tutmaz.
    |
    | ANAHTAR APP_KEY'DEN AYRI TUTULABİLİR ve tutulmalıdır. APP_KEY'e düşmek
    | çalışır ama iki sakıncası var: .env'i okuyabilen biri zinciri yeniden
    | hesaplayabilir, ve APP_KEY döndürüldüğü gün bütün geçmiş "kırık"
    | görünür.
    |
    | BOŞ DEĞER = VERİLMEMİŞ: compose değişkeni listeden geçirir; .env'de
    | boş kalan "AUDIT_KEY=" satırı konteynere boş dize olarak
    | gelir. env()'in varsayılanı yalnız değişken HİÇ yokken devreye girdiği
    | için zincir boş anahtarla imzalanırdı. ?: boşu da APP_KEY'e düşürür.
    */
    'chain_key' => env('AUDIT_KEY') ?: env('APP_KEY'),

    /*
    | Zincirin başlangıç noktası. Budama eski satırları sildiğinde en son
    | silinen satırın özeti buraya yazılır; doğrulama kalan ilk satırın oradan
    | devam ettiğini görür.
    */
    'chain_anchor_key' => 'denetim:zincir-capasi',

    /*
    | Doğrulama sonucu bu anahtarla önbelleğe yazılır; ekran her açılışta
    | bütün satırları yeniden özetlemek yerine buradan okur.
    */
    'verification_key' => 'audit:verification',
];
