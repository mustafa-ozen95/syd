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
];
