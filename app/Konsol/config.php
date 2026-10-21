<?php

declare(strict_types=1);

use App\Konsol\Kaynaklar\JunosTablosuKaynagi;
use App\Konsol\Kaynaklar\YedekDeposuKaynagi;

/*
|--------------------------------------------------------------------------
| KONSOLDAN KURULUM MODÜLÜ
|--------------------------------------------------------------------------
|
| Panel ne kurulacağına karar verir; sahadaki PC'de çalışan ajan
| (konsol-ajan/) PC'ye takılı USB-seri konsol kablosundan switch'i sürer:
| loader'a indirir, adres verir, `install --format tftp://...` yazar,
| sürümü doğrular, istenmişse yedeği yükler. Görüntü switch'in mgmt
| portundan TFTP sunucusundan iner (elle kurulumdaki yöntemin aynısı).
|
| MODÜL KENDİ İÇİNDE: başka panele taşımak için app/Konsol ve
| konsol-ajan/ kopyalanır, KonsolServiceProvider kaydedilir.
|
*/

return [
    /* Kurulabilecek Junos görüntüleri (Contracts\GoruntuKaynagi). SYD'de Kurulum sayfası. */
    'goruntu_kaynagi' => JunosTablosuKaynagi::class,

    /* Cihazların yedekleri (Contracts\YapilandirmaKaynagi): "son yedeği yükle". */
    'yapilandirma_kaynagi' => YedekDeposuKaynagi::class,

    /*
    | Loader'da yazılanlar. Yer tutucular: {ip} {maske} {gecit} {sunucu}
    | {adres}. Adlar U-Boot standardı (set ad=değer).
    */
    'loader_komutlari' => [
        'set ipaddr={ip}',
        'set netmask={maske}',
        'set gatewayip={gecit}',
    ],
    'kurulum_komutu' => 'install --format {adres}',

    /* TFTP sunucusunun ilerleme dosyası (compose: tftp-durum hacmi). */
    'tftp_durum' => env('KONSOL_TFTP_DURUM', '/durum/tftp.json'),

    /* TFTP 512 baytlık bloklarla iner; büyük görüntüler (EX2300) saatler sürebilir. */
    'kurulum_dakika' => (int) env('KONSOL_KURULUM_DAKIKA', 240),

    /* İş başına saklanan konsol çıktısının üst sınırı (bayt). */
    'cikti_siniri' => 8 * 1024 * 1024,

    /* Derlenmiş ajanların dizini; imaj derlenirken doldurulur (Dockerfile "konsol-ajan"). */
    'ajan_dizini' => env('KONSOL_AJAN_DIZINI', base_path('konsol-ajan/dagitim')),

    'ajan_dosyalari' => [
        'linux' => ['dosya' => 'konsol-ajan-linux-amd64', 'etiket' => 'Linux (x86-64)'],
        'windows' => ['dosya' => 'konsol-ajan-windows-amd64.exe', 'etiket' => 'Windows (x86-64)'],
    ],

    /* Saha PC'lerine bağlanan yardımcılar (bkz. Uzak\WinRm, Uzak\Ssh). */
    'winrm' => [
        'python' => env('KONSOL_PYTHON', '/usr/bin/python3'),
        'runner' => app_path('Konsol/bin/winrm-runner.py'),
        'openssl_conf' => app_path('Konsol/bin/openssl-legacy.cnf'),
        'timeout' => (int) env('KONSOL_WINRM_TIMEOUT', 60),
    ],
    'ssh' => [
        'python' => env('KONSOL_PYTHON', '/usr/bin/python3'),
        'runner' => app_path('Konsol/bin/ssh-runner.py'),
        'timeout' => (int) env('KONSOL_SSH_TIMEOUT', 60),
    ],

    /*
    | Panelin TLS sertifikası. KENDİNDEN İMZALIYSA ajan kurulurken PC'ye
    | bırakılır ve bağlantılar ona sabitlenir.
    */
    'panel_sertifikasi' => env('KONSOL_PANEL_SERTIFIKASI', storage_path('app/tls/syd.crt')),
];
