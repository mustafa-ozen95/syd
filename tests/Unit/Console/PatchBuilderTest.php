<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\ConsoleModule\Junos\ConfigTree;
use App\ConsoleModule\Junos\Md5Crypt;
use App\ConsoleModule\Junos\PatchBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatchBuilderTest extends TestCase
{
    private function agac(): ConfigTree
    {
        return ConfigTree::parse((string) file_get_contents(__DIR__.'/../../Fixtures/junos/ornek-ex.conf'));
    }

    #[Test]
    public function yedekteki_degerler_okunur(): void
    {
        $alanlar = (new PatchBuilder)->alanlar($this->agac());

        $this->assertSame('sw-ornek.example.test', $alanlar['host_name']);
        $this->assertSame(['ge-0/0/23', '10.0.0.38/30', '10.0.0.37'], [$alanlar['uplink_arayuz'], $alanlar['uplink_adres'], $alanlar['uplink_gecit']]);
        $this->assertSame('192.0.2.16/24', $alanlar['me0_adres']);
        /* inactive: ile kapatılmış adres cihazda çalışmaz: gelmez. */
        $this->assertSame(['198.51.100.1/29'], $alanlar['vlan0_adresleri']);
        $this->assertSame(['10.0.0.0/24', '198.51.100.0/29'], $alanlar['koru']);
        $this->assertSame(['203.0.113.5/32'], $alanlar['re_filter']);
        $this->assertNull($alanlar['me_filter'], 'yedekte ME_FILTER yok');
        $this->assertSame(['genel', 'eski'], $alanlar['snmp_communityler']);
        $this->assertTrue($alanlar['archival'] && $alanlar['snmp_engine'] && $alanlar['admin_var']);
    }

    #[Test]
    public function degismeyen_alan_satir_uretmez(): void
    {
        $b = new PatchBuilder;
        $t = $this->agac();
        $oz = $b->alanlar($t);
        unset($oz['me_filter']);

        $this->assertSame([], $b->satirlar($t, $oz + ['root_ozet' => '', 'admin_ozet' => '']));
    }

    #[Test]
    public function uplink_listeler_parolalar_ve_ek_komutlar_yamaya_doner(): void
    {
        $b = new PatchBuilder;
        $ozet = Md5Crypt::ozet('Yeni-Parola-1');
        $satirlar = $b->satirlar($this->agac(), [
            'host_name' => 'piga',
            'uplink_arayuz' => 'xe-0/1/0', 'uplink_adres' => '10.0.0.230/30', 'uplink_gecit' => '10.0.0.229',
            'me0_kaldir' => true,
            're_filter' => ['198.51.100.22/32'],
            'koru' => ['10.0.0.0/24', '203.0.113.0/27'],
            'snmp_communityler' => ['genel'], 'snmp_engine_kaldir' => true,
            'root_ozet' => $ozet, 'archival_kaldir' => true,
            'ek_komutlar' => ['set system ntp server 198.51.100.10'],
        ]);

        $this->assertSame([
            'set system host-name piga',
            'delete interfaces ge-0/0/23',
            'set interfaces ge-0/0/23 unit 0 family ethernet-switching',
            'delete interfaces xe-0/1/0 unit 0 family ethernet-switching',
            'delete vlans default interface xe-0/1/0.0',
            'delete interfaces xe-0/1/0 unit 0 family inet address',
            'set interfaces xe-0/1/0 description UPLINK',
            'set interfaces xe-0/1/0 unit 0 family inet address 10.0.0.230/30',
            'set interfaces xe-0/1/0 unit 0 family inet filter output koruma',
            'delete routing-options static route 0.0.0.0/0',
            'set routing-options static route 0.0.0.0/0 next-hop 10.0.0.229',
            'delete interfaces me0 unit 0 family inet address',
            'delete firewall family inet filter RE_FILTER term Kabul from source-address',
            'set firewall family inet filter RE_FILTER term Kabul from source-address 198.51.100.22/32',
            'delete policy-options prefix-list koru',
            'set policy-options prefix-list koru 10.0.0.0/24',
            'set policy-options prefix-list koru 203.0.113.0/27',
            'delete snmp engine-id',
            'delete snmp community eski',
            "set system root-authentication encrypted-password \"{$ozet}\"",
            'delete system archival',
            'set system ntp server 198.51.100.10',
        ], $satirlar);
        $this->assertStringContainsString('"<gizli>"', implode("\n", PatchBuilder::maskele($satirlar)));
        $this->assertStringNotContainsString($ozet, implode("\n", PatchBuilder::maskele($satirlar)));
    }

    #[Test]
    public function parola_ozeti_junos_bicimindedir(): void
    {
        $ozet = Md5Crypt::ozet('Yeni-Parola-1');

        $this->assertMatchesRegularExpression(Md5Crypt::DESEN, $ozet);
        $this->assertSame($ozet, crypt('Yeni-Parola-1', $ozet), 'özet parolayı doğrular');
        $this->assertSame($ozet, Md5Crypt::ozet($ozet), 'hazır özet olduğu gibi kalır');
    }
}
