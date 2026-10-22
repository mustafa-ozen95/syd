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
            'serial_port' => (string) $pc->seri_port,
            'device_ip' => $ip,
            /* DHCP'de adres satırları yazılmaz; loader adresi kendisi alır. */
            'loader_commands' => array_values(array_map(fn (string $s): string => strtr($s, $yerler), array_filter(
                (array) config('console.loader_commands'),
                fn (string $s): bool => ! $dhcp || ! preg_match('/\{(ip|maske|gecit)\}/', $s),
            ))),
            'install_command' => strtr((string) config('console.install_command'), $yerler),
            'install_minutes' => (int) config('console.install_minutes'),
            'expected_version' => (string) $is->beklenen_surum,
            'password' => (string) $is->root_parola,
            'configuration' => $is->yapilandirma_turu !== 'yok',
            'load_command' => 'load override terminal',
            'delete_first' => false,
        ];
    }

    /**
     * Dosya adındaki Junos sürümü: "jinstall-ex-2200-12.3R12.4-domestic-signed.tgz"
     * → "12.3R12.4", "junos-vmhost-install-mx-x86-64-23.4R2-S8.7.tgz" → "23.4R2-S8.7".
     * Bulunamazsa null (sürüm doğrulanmaz, yalnız ekrana yazılır).
     */
    public static function surum(string $dosya): ?string
    {
        return preg_match('/(\d+\.\d+[RX]\d+(?:-S\d+)?(?:\.\d+)?)/', $dosya, $m) ? $m[1] : null;
    }
}
