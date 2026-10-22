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
| yeni görüntü eklemek koda dokunmayı gerektirmez. Dosyanın kendisi de
| panelden yüklenir, adı değişir ve silinir: panel TFTP'nin hacmini
| yazılabilir bağlar (filesystems: junos disk). Yükleme sınırı nginx'te de
| (conf.d/syd.conf, /setup/image) aynı olmalı.
|
*/

return [
    'tftp' => env('JUNOS_TFTP', 'tftp://192.0.2.5'),

    /* Tek görüntü dosyasının üst sınırı (MB). MX vmhost görüntüleri ~3 GB. */
    'upload_max_mb' => 4096,
];
