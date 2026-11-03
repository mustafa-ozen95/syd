<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Junos\Md5Crypt;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Services\AgentJob;
use App\Models\ApiToken;
use App\Models\JunosImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** QFX5100/QFX5110/EX4600: loader yok; Junos CLI'den, görüntü HTTP'den. */
final class ConsoleCliInstallTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function qfx_paketi_loaderdan_reddedilir_cliden_kurulur(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = ConsolePc::create([
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'port' => 5985, 'kullanici' => 'OPERATÖR',
            'parola' => 'x', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => 'C:\\Console-Agent', 'seri_port' => 'COM3', 'seri_portlar' => ['COM3'],
        ]);
        $qfx = JunosImage::create(['model' => 'QFX5100', 'surum' => '17.2R1', 'dosya' => 'jinstall-host-qfx-5-17.2R1.13-signed.tgz', 'sira' => 900]);

        /* Loader kurulumu: bu modelde loader yok. */
        $this->actingAs($yonetici)->post(route('console.store'), ['pc' => $pc->uuid, 'ad' => '', 'goruntu' => (string) $qfx->id])
            ->assertSessionHasErrors('goruntu');

        $this->actingAs($yonetici)->post(route('console.store'), ['pc' => $pc->uuid, 'ad' => '', 'islem' => 'cli', 'goruntu' => (string) $qfx->id, 'root_parola' => 'Yeni-Parola-1'])
            ->assertSessionHasNoErrors();
        $is = ConsoleJob::sole();
        /* Cihaz sunucuya ulaşmıyorsa ajanın geçici olarak vereceği adres: PC kaydından. */
        $this->assertSame(['cli', '198.51.100.120/24', '17.2R1.13'], [$is->islem, $is->cihaz_ip, $is->beklenen_surum]);
        $this->assertStringStartsWith("CLI'den kurulum", $is->islemOzeti());

        $sir = ApiToken::mint();
        $jeton = ApiToken::create(['name' => 'Konsol ajanı', 'user_id' => $yonetici->id, 'token_hash' => $sir['hash'],
            'prefix' => $sir['prefix'], 'abilities' => ['konsol' => ['view', 'ajan']]]);
        $pc->forceFill(['jeton_id' => $jeton->id])->save();
        $this->app['auth']->forgetGuards();

        $veri = $this->withToken($sir['plain'])->getJson(route('api.v1.console.jobs'))->assertOk()->json('data.0');
        $this->assertTrue($veri['cli_install']);
        $this->assertSame([], $veri['loader_commands']);
        $this->assertSame('request system software add http://192.0.2.5:8069/jinstall-host-qfx-5-17.2R1.13-signed.tgz reboot', $veri['install_command']);
        $this->assertSame(['198.51.100.120/24', '198.51.100.1', '192.0.2.5'], [$veri['cli_address'], $veri['cli_gateway'], $veri['image_server']]);
        /* Cihaza düz parola değil özet gider. */
        $this->assertTrue(Md5Crypt::ozetMi($veri['root_hash']));
        $this->assertSame($veri['root_hash'], crypt('Yeni-Parola-1', $veri['root_hash']));
    }

    /** DHCP'de adres verilmez; aynı PC'de açık işin adresi CLI'de de reddedilir. */
    #[Test]
    public function cli_kurulum_adresi_dhcpde_bos_ayni_adres_reddedilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = ConsolePc::create([
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'port' => 5985, 'kullanici' => 'OPERATÖR',
            'parola' => 'x', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => 'C:\\Console-Agent', 'seri_port' => 'COM3', 'seri_portlar' => ['COM3', 'COM9'],
        ]);
        $qfx = JunosImage::create(['model' => 'QFX5100', 'surum' => '17.2R1', 'dosya' => 'jinstall-host-qfx-5-17.2R1.13-signed.tgz', 'sira' => 900]);
        $is = fn (array $ek) => ['pc' => $pc->uuid, 'ad' => '', 'islem' => 'cli', 'goruntu' => (string) $qfx->id, 'seri_port' => 'COM3'] + $ek;

        $this->actingAs($yonetici)->post(route('console.store'), $is(['adres_turu' => 'dhcp']))->assertSessionHasNoErrors();
        $veri = app(AgentJob::class)->hazirla(ConsoleJob::sole());
        $this->assertSame(['', '', ''], [$veri['cli_address'], $veri['cli_gateway'], $veri['root_hash']]);

        ConsoleJob::query()->update(['adres_turu' => 'sabit', 'cihaz_ip' => '198.51.100.121/24']);
        $this->actingAs($yonetici)->post(route('console.store'), $is(['seri_port' => 'COM9', 'cihaz_ip' => '198.51.100.121/24']))
            ->assertSessionHasErrors('cihaz_ip');
    }
}
