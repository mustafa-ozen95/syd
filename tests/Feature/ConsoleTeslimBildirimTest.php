<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\Api\ApiTokenContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Teslim anındaki yapılandırma, "iş bitti" bildirimi ve iş açmadan önce kontrol. */
final class ConsoleTeslimBildirimTest extends TestCase
{
    use RefreshDatabase;

    private function pc(): ConsolePc
    {
        return ConsolePc::create([
            'ad' => 'Linux PC', 'platform' => 'linux', 'adres' => '198.51.100.15', 'port' => 22, 'kullanici' => 'root',
            'parola' => 'x', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => '/opt/console-agent', 'ssh_parmak_izi' => 'SHA256:abc',
        ]);
    }

    /** @return array{0: ConsoleJob, 1: string} iş ve ajan jetonu */
    private function ajanliIs(User $kim): array
    {
        $pc = $this->pc();
        $is = ConsoleJob::create(['pc_id' => $pc->id, 'ad' => 'sw-a', 'olusturan_id' => $kim->id, 'acik_port' => '']);
        $sir = ApiToken::mint();
        $jeton = ApiToken::create(['name' => 'Konsol ajanı', 'user_id' => $kim->id, 'token_hash' => $sir['hash'],
            'prefix' => $sir['prefix'], 'abilities' => ['konsol' => ['view', 'ajan']]]);
        $pc->forceFill(['jeton_id' => $jeton->id])->save();

        return [$is, $sir['plain']];
    }

    /** Ajan isteğinden sonra panel kullanıcısı: jeton başlığı ve bağlamı düşer. */
    private function ajandanCik(): void
    {
        $this->withoutToken();
        $this->app['auth']->forgetGuards();
        app(ApiTokenContext::class)->clear();
    }

    #[Test]
    public function teslim_configi_sifreli_saklanir_yetkiliye_iner_iki_gun_sonra_silinir(): void
    {
        $yonetici = User::factory()->admin()->create();
        [$is, $jeton] = $this->ajanliIs($yonetici);
        $config = "system {\n    root-authentication {\n        encrypted-password \"\$1\$abc\";\n    }\n}";

        $this->withToken($jeton)->postJson(route('api.v1.console.final-config', $is), ['text' => $config])->assertOk();
        $this->ajandanCik();
        $this->assertStringNotContainsString('root-authentication', (string) $is->refresh()->getRawOriginal('teslim_config'), 'şifreli saklanır');

        $this->actingAs($yonetici)->get(route('console.show', $is))->assertSee("Teslim config'i", false);
        $this->actingAs($yonetici)->get(route('console.final-config', $is))->assertOk()->assertSee('root-authentication', false);

        /* Yedek indiremeyen (teknik) göremez de indiremez de. */
        $teknik = User::factory()->rol('teknik')->create();
        $this->actingAs($teknik)->get(route('console.show', $is))->assertDontSee("Teslim config'i", false);
        $this->actingAs($teknik)->get(route('console.final-config', $is))->assertRedirect(route('forbidden'));

        /* Kapalı işe ajan yazamaz. */
        $is->kapat('tamam', null);
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson(route('api.v1.console.final-config', $is), ['text' => 'x'])->assertStatus(409);
        $this->ajandanCik();

        Process::fake();
        $this->travel(47)->hours();
        $this->artisan('console:agents');
        $this->assertNotNull($is->refresh()->teslim_config);
        $this->travel(2)->hours();
        $this->artisan('console:agents');
        $this->assertNull($is->refresh()->teslim_config);
        $this->assertNull($is->teslim_config_at);
    }

    #[Test]
    public function biten_is_acana_bir_kez_bildirilir(): void
    {
        $acan = User::factory()->admin()->create();
        $baskasi = User::factory()->admin()->create();
        [$is] = $this->ajanliIs($acan);

        $this->actingAs($acan)->getJson(route('console.notifications'))->assertOk()
            ->assertJsonCount(0, 'bildirimler')->assertJsonPath('sonraki', 20);

        $is->kapat('hata', 'Junos açılmadı');
        $this->actingAs($baskasi)->getJson(route('console.notifications'))->assertJsonCount(0, 'bildirimler');
        $this->actingAs($acan)->getJson(route('console.notifications'))
            ->assertJsonPath('bildirimler.0.durum', 'hata')
            ->assertJsonPath('bildirimler.0.mesaj', 'sw-a: iş hatayla bitti. Junos açılmadı')
            ->assertJsonPath('bildirimler.0.url', route('console.show', $is))
            ->assertJsonPath('sonraki', 120);
        $this->actingAs($acan)->getJson(route('console.notifications'))->assertJsonCount(0, 'bildirimler');

        $this->actingAs(User::factory()->rol('muhasebe')->create())->get(route('console.notifications'))->assertRedirect(route('forbidden'));
    }

    #[Test]
    public function on_kontrol_pc_kablo_ve_adresi_switche_dokunmadan_sinar(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        $komutlar = [];
        Process::fake(function ($p) use (&$komutlar) {
            $betik = (string) json_decode((string) $p->input, true)['script'];
            $komutlar[] = $betik;
            $cikti = str_contains($betik, 'by-id') ? "/dev/serial/by-id/usb-FTDI_A50285BI-if00-port0|/dev/ttyUSB0|usb-FTDI_A50285BI-if00-port0\n"
                : (str_contains($betik, 'ping') ? "CEVAP\n" : '');

            return Process::result((string) json_encode(['ok' => true, 'status' => 0, 'stdout' => base64_encode($cikti), 'stderr' => '', 'fingerprint' => 'SHA256:abc']));
        });

        $yanit = $this->actingAs($yonetici)->postJson(route('console.precheck'), [
            'pc' => $pc->uuid, 'seri_port' => '/dev/serial/by-id/usb-FTDI_A50285BI-if00-port0', 'cihaz_ip' => '198.51.100.130/24',
        ])->assertOk();

        $this->assertSame(['tamam', 'tamam', 'hata'], array_column($yanit->json('sonuclar'), 'durum'));
        $this->assertStringContainsString('198.51.100.130 başka bir cihaz', $yanit->json('sonuclar.2.metin'));
        $this->assertStringContainsString('ping -c 2 -W 1 198.51.100.130', $komutlar[1]);

        /* Komuta giren adres biçimi dar: kabuk karakteri reddedilir. */
        $this->actingAs($yonetici)->postJson(route('console.precheck'), ['pc' => $pc->uuid, 'cihaz_ip' => '1.1.1.1;reboot'])
            ->assertStatus(422);
        /* Takılı olmayan kablo. */
        $this->actingAs($yonetici)->postJson(route('console.precheck'), ['pc' => $pc->uuid, 'seri_port' => '/dev/serial/by-id/baska', 'adres_turu' => 'dhcp'])
            ->assertJsonPath('sonuclar.1.durum', 'hata')->assertJsonCount(2, 'sonuclar');
    }
}
