<?php

declare(strict_types=1);

use App\ConsoleModule\Sources\BackupStoreSource;
use App\ConsoleModule\Sources\JunosTableSource;

/*
|--------------------------------------------------------------------------
| KONSOLDAN KURULUM MODÜLÜ
|--------------------------------------------------------------------------
|
| Panel ne kurulacağına karar verir; sahadaki PC'de çalışan ajan
| (console-agent/) PC'ye takılı USB-seri konsol kablosundan switch'i sürer:
| loader'a indirir, adres verir, `install --format tftp://...` yazar,
| sürümü doğrular, istenmişse yedeği yükler. Görüntü switch'in mgmt
| portundan TFTP sunucusundan iner (elle kurulumdaki yöntemin aynısı).
|
| MODÜL KENDİ İÇİNDE: başka panele taşımak için app/ConsoleModule ve
| console-agent/ kopyalanır, ConsoleServiceProvider kaydedilir.
|
*/

return [
    /* Kurulabilecek Junos görüntüleri (Contracts\ImageSource). SYD'de Kurulum sayfası. */
    'image_source' => JunosTableSource::class,

    /* Cihazların yedekleri (Contracts\ConfigurationSource): "son yedeği yükle". */
    'configuration_source' => BackupStoreSource::class,

    /*
    | Loader'da yazılanlar. Yer tutucular: {ip} {maske} {gecit} {sunucu}
    | {adres}. Adlar U-Boot standardı (set ad=değer).
    */
    'loader_commands' => [
        'set ipaddr={ip}',
        'set netmask={maske}',
        'set gatewayip={gecit}',
    ],
    'install_command' => 'install --format {adres}',

    /* TFTP sunucusunun ilerleme dosyası (compose: tftp-durum hacmi). */
    'tftp_state' => env('CONSOLE_TFTP_STATE', '/durum/tftp.json'),

    /* TFTP 512 baytlık bloklarla iner; büyük görüntüler (EX2300) saatler sürebilir. */
    'install_minutes' => (int) env('CONSOLE_INSTALL_MINUTES', 240),

    /* İş başına saklanan konsol çıktısının üst sınırı (bayt). */
    'output_limit' => 8 * 1024 * 1024,

    /* Derlenmiş ajanların dizini; imaj derlenirken doldurulur (Dockerfile "console-agent"). */
    'agent_dir' => env('CONSOLE_AGENT_DIR', base_path('console-agent/dist')),

    'agent_files' => [
        'linux' => ['dosya' => 'console-agent-linux-amd64', 'etiket' => 'Linux (x86-64)'],
        'windows' => ['dosya' => 'console-agent-windows-amd64.exe', 'etiket' => 'Windows (x86-64)'],
    ],

    /* Saha PC'lerine bağlanan yardımcılar (bkz. Uzak\WinRm, Uzak\Ssh). */
    'winrm' => [
        'python' => env('CONSOLE_PYTHON', '/usr/bin/python3'),
        'runner' => app_path('ConsoleModule/bin/winrm-runner.py'),
        'openssl_conf' => app_path('ConsoleModule/bin/openssl-legacy.cnf'),
        'timeout' => (int) env('CONSOLE_WINRM_TIMEOUT', 60),
    ],
    /*
    | Ajanın PC'ye kurulması (indirme + Windows'ta Add-Type derlemesi) için
    | süre, saniye. Yavaş hatta aşılırsa jeton iptal edilir ve bir dakika
    | sonra yeniden denenir.
    */
    'install_timeout' => (int) env('CONSOLE_INSTALL_TIMEOUT', 300),

    /*
    | Ajan birkaç saniyede bir panele gelir; jetonu bu kadar dakika
    | kullanılmazsa ajan ölü sayılır ve açık iş için yeniden kurulur.
    */
    'agent_stale_minutes' => (int) env('CONSOLE_AGENT_STALE_MINUTES', 5),

    /* Düşen ajan kurulumu en seyrek kaç dakikada bir denenir (1, 2, 4, 8, …). */
    'agent_retry_max_minutes' => (int) env('CONSOLE_AGENT_RETRY_MAX_MINUTES', 15),

    /*
    | Açık iş bu kadar dakika hiç ilerlemezse (olay, çıktı) "hata" ile kapanır.
    | Kurulum sürerken ajan beş dakikada bir haber verir.
    */
    'job_idle_minutes' => (int) env('CONSOLE_JOB_IDLE_MINUTES', 120),

    'ssh' => [
        'python' => env('CONSOLE_PYTHON', '/usr/bin/python3'),
        'runner' => app_path('ConsoleModule/bin/ssh-runner.py'),
        'timeout' => (int) env('CONSOLE_SSH_TIMEOUT', 60),
    ],

    /*
    | Panelin TLS sertifikası. KENDİNDEN İMZALIYSA ajan kurulurken PC'ye
    | bırakılır ve bağlantılar ona sabitlenir.
    */
    'panel_certificate' => env('CONSOLE_PANEL_CERTIFICATE', storage_path('app/tls/syd.crt')),

    /*
    | Önce panelin adresinde (APP_URL) gerçekten sunulan sertifikaya bakılır;
    | önde Traefik gibi bir vekil varsa PC'nin göreceği odur. Ulaşılamazsa
    | yukarıdaki dosya kullanılır.
    */
    'panel_certificate_probe' => (bool) env('CONSOLE_PANEL_CERTIFICATE_PROBE', true),
];
