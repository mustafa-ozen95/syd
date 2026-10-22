<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Yedek deposu
|--------------------------------------------------------------------------
| Switch yapılandırma yedeklerinin durduğu dizin. Konteynere SALT-OKUNUR
| bağlanır (bkz. docker-compose.yml): panelin yazma ucu yok ve kod
| yanlışlıkla yazmaya kalksa bile dosya sistemi izin vermez.
|
| Buradaki dosyalar çoğu zaman cihaz yedeklerinin TEK KOPYASIDIR; bir arıza
| anında elde kalan yegâne yapılandırma kaydı.
*/

return [

    'root' => env('BACKUP_ROOT', '/yedekler'),

    /*
    | Bir cihazın klasöründe binlerce yedek olabiliyor; hepsini tek sayfada
    | çizmek tarayıcıyı kilitliyordu.
    */
    'page_size' => (int) env('BACKUP_PAGE_SIZE', 50),

];
