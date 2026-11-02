<?php

declare(strict_types=1);

namespace App\ConsoleModule\Services;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;

/**
 * Ajanın gördüğü iş: konsolda ne yazılacağı burada hazırlanır, ajan yalnız
 * istemleri bekleyip yazar. Komutların metni config'tedir (konsol.loader_komutlari).
 */
class AgentJob
{
    /** @return array<string, mixed> */
    public function hazirla(ConsoleJob $is): array
    {
        $pc = $is->pc;

        /* Yalnız yedek: loader'a inilmez, kurulmaz; ajan CLI'ye girip yükler. */
        if ($is->yalnizYedekMi()) {
            return [
                'uuid' => $is->uuid,
                'resume_stage' => $this->devamAsamasi($is),
                'patch' => $is->yama !== null,
                'uplink_interface' => (string) $is->uplink_arayuz,
                'patch' => $is->yama !== null,
                'uplink_interface' => (string) $is->uplink_arayuz,
                'serial_port' => $this->port($is),
                'device_ip' => '',
                'loader_commands' => [],
                'install_command' => '',
                'install_minutes' => 0,
                'expected_version' => '',
                'password' => (string) $is->root_parola,
                'configuration' => true,
                'load_command' => 'load override terminal',
                'delete_first' => false,
                'load_only' => true,
            ];
        }

        /* CLI'den kurulum: loader yok; görüntü TFTP sunucusunun HTTP'sinden. */
        if ($is->clidenMi()) {
            $adres = parse_url((string) $is->goruntu_adres);
            $http = 'http://'.($adres['host'] ?? '').':'.(int) config('console.image_http_port').'/'.basename((string) ($adres['path'] ?? ''));

            return [
                'uuid' => $is->uuid,
                'resume_stage' => $this->devamAsamasi($is),
                'patch' => $is->yama !== null,
                'uplink_interface' => (string) $is->uplink_arayuz,
                'serial_port' => $this->port($is),
                'device_ip' => '',
                'loader_commands' => [],
                'uboot_commands' => [],
                'cli_install' => true,
                'install_command' => strtr((string) config('console.cli_install_command'), ['{http}' => $http]),
                'install_minutes' => (int) config('console.install_minutes'),
                'expected_version' => (string) $is->beklenen_surum,
                'password' => (string) $is->root_parola,
                'configuration' => $is->yapilandirma_turu !== 'yok',
                'load_command' => 'load override terminal',
                'delete_first' => false,
                'load_only' => false,
            ];
        }

        $dhcp = $is->adres_turu === 'dhcp';
        [$ip, $maske] = $dhcp ? ['', ''] : ConsolePc::adresCoz((string) ($is->cihaz_ip ?? $pc->cihaz_ip));

        $yerler = [
            '{ip}' => $ip,
            '{maske}' => $maske,
            '{gecit}' => (string) ($is->gecit ?? $pc->gecit),
            '{sunucu}' => (string) parse_url((string) $is->goruntu_adres, PHP_URL_HOST),
            '{adres}' => (string) $is->goruntu_adres,
        ];

        return [
            'uuid' => $is->uuid,
            'resume_stage' => $this->devamAsamasi($is),
            'patch' => $is->yama !== null,
            'uplink_interface' => (string) $is->uplink_arayuz,
            'serial_port' => $this->port($is),
            'device_ip' => $ip,
            /* DHCP'de adres satırları yazılmaz; loader adresi kendisi alır. */
            'loader_commands' => self::komutlar('console.loader_commands', $yerler, $dhcp),
            'uboot_commands' => self::komutlar('console.uboot_commands', $yerler, $dhcp),
            'cli_install' => false,
            'install_command' => strtr((string) config('console.install_command'), $yerler),
            'install_minutes' => (int) config('console.install_minutes'),
            'expected_version' => (string) $is->beklenen_surum,
            'password' => (string) $is->root_parola,
            'configuration' => $is->yapilandirma_turu !== 'yok',
            'load_command' => 'load override terminal',
            'delete_first' => false,
            'load_only' => false,
        ];
    }

    /**
     * Dosya adındaki Junos sürümü: "jinstall-ex-2200-12.3R12.4-domestic-signed.tgz"
     * → "12.3R12.4", "junos-vmhost-install-mx-x86-64-23.4R2-S8.7.tgz" → "23.4R2-S8.7".
     * Bulunamazsa null (sürüm doğrulanmaz, yalnız ekrana yazılır).
     */
    public static function surum(string $dosya): ?string
    {
        /* 12.3R12.4, 18.4R3-S10, 15.1X53-D55.5 (D'li X dalı: EX2300/EX3400). */
        return preg_match('/(\d+\.\d+[RX]\d+(?:-[SD]\d+)?(?:\.\d+)?)/', $dosya, $m) ? $m[1] : null;
    }

    /**
     * Konsoldan (loader) kurulamayacak paket için açıklama; kurulabiliyorsa null.
     * Juniper belgelerine göre:
     *  - QFX5100, QFX5110, EX4600'de loader istemi yoktur (jinstall-host-qfx-*):
     *    kurulum CLI'den (request system software add) ya da USB'den yapılır.
     *  - EX2300/EX3400'de loader'dan yalnız "install-media-net" paketi kurulur
     *    (junos-install-media-net-arm-32-*); düz junos-arm-32-* yalnız CLI'dendir.
     */
    public static function konsoldanKurulamaz(string $dosya): ?string
    {
        $ad = strtolower(basename($dosya));

        return match (true) {
            str_contains($ad, 'jinstall-host') || str_contains($ad, '-qfx-5') => 'Bu paket QFX5100/QFX5110/EX4600 içindir: bu modellerde loader yok, konsoldan TFTP ile kurulamaz. Switch açıkken CLI\'den "request system software add <paket> reboot" ya da USB kurulum medyası kullanın.',
            str_contains($ad, 'arm-32') && ! str_contains($ad, 'install-media') => 'EX2300/EX3400 loader\'dan yalnız "junos-install-media-net-arm-32-…tgz" paketiyle kurulur; "junos-arm-32-…" paketi yalnız CLI yükseltmesi içindir.',
            default => null,
        };
    }

    /**
     * @param  array<string, string>  $yerler
     * @return list<string>
     */
    private static function komutlar(string $anahtar, array $yerler, bool $dhcp): array
    {
        return array_values(array_map(fn (string $s): string => strtr($s, $yerler), array_filter(
            (array) config($anahtar),
            fn (string $s): bool => ! $dhcp || ! preg_match('/\{(ip|maske|gecit)\}/', $s),
        )));
    }

    /**
     * Ajan işi yeniden alıyorsa (PC ya da ajan iş ortasında yeniden başladı)
     * işin vardığı aşama; ilk alışta boş. Ajan buna göre loader'a inmeden
     * kurulumun bitmesini bekler ya da yalnız yapılandırmayı yükler: yoksa
     * kurulum sürerken cihaz yeniden loader'a indirilip baştan kuruluyordu.
     */
    private function devamAsamasi(ConsoleJob $is): string
    {
        return $is->durum === 'calisiyor' ? (string) $is->asama : '';
    }

    /** İşin portu; eski işte ya da otomatikte PC'nin (tek) portu. */
    private function port(ConsoleJob $is): string
    {
        return (string) ($is->seri_port ?: $is->pc->seri_port);
    }
}
