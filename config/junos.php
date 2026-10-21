<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JUNOS TFTP YEREL KURULUM
|--------------------------------------------------------------------------
|
| Kurulum sayfası (bkz. SetupController). Görüntüler bu kurulumun TFTP'sinden (compose: tftp)
| sunucusundan çekilir; komut switch'in CLI'ında (operational mode)
| çalıştırılır.
|
| Görüntü listesi VERİTABANINDA (junos_goruntuleri) ve panelden yönetilir;
| yeni görüntü eklemek koda dokunmayı gerektirmez.
|
*/

return [
    'tftp' => env('JUNOS_TFTP', 'tftp://192.0.2.5'),
];
