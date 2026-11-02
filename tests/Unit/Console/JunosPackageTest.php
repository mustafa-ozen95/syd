<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\ConsoleModule\Services\AgentJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Junos paket adından sürüm ve konsoldan kurulabilirlik (Juniper KB20643, KB31462, kurtarma belgesi). */
final class JunosPackageTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function surumler(): array
    {
        return [
            'EX2200' => ['jinstall-ex-2200-12.3R12.4-domestic-signed.tgz', '12.3R12.4'],
            'EX4200 S' => ['jinstall-ex-4200-15.1R7-S6.3-domestic-signed.tgz', '15.1R7-S6.3'],
            'EX4300 X/D' => ['jinstall-ex-4300-13.2X50-D18-domestic-signed.tgz', '13.2X50-D18'],
            'EX2300 X/D' => ['junos-install-media-net-arm-32-15.1X53-D55.5.tgz', '15.1X53-D55.5'],
            'QFX5110' => ['jinstall-host-qfx-5e-x86-64-18.4R3-S10-secure-signed.tgz', '18.4R3-S10'],
        ];
    }

    #[Test]
    #[DataProvider('surumler')]
    public function surum_dosya_adindan_okunur(string $dosya, ?string $beklenen): void
    {
        $this->assertSame($beklenen, AgentJob::surum($dosya));
    }

    #[Test]
    public function loaderi_olmayan_ve_cli_paketleri_reddedilir(): void
    {
        $this->assertNull(AgentJob::konsoldanKurulamaz('jinstall-ex-3300-12.3R12.4-domestic-signed.tgz'));
        $this->assertNull(AgentJob::konsoldanKurulamaz('junos-install-media-net-arm-32-15.1X53-D55.5.tgz'));
        $this->assertStringContainsString('loader yok', (string) AgentJob::konsoldanKurulamaz('jinstall-host-qfx-5-17.2R1.13-signed.tgz'));
        $this->assertStringContainsString('loader yok', (string) AgentJob::konsoldanKurulamaz('jinstall-host-qfx-5e-x86-64-18.4R3-S10-secure-signed.tgz'));
        $this->assertStringContainsString('install-media', (string) AgentJob::konsoldanKurulamaz('junos-arm-32-18.4R2-S4.10.tgz'));
    }
}
