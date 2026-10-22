<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\Models\ApiToken;
use App\Models\JunosImage;
use App\Models\User;
use App\Services\Api\ApiTokenContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Anlık ajan: iş açılınca ajan PC'ye kurulur, açık iş kalmayınca
 * kaldırılır; ajan yalnız kendi PC'sinin işlerini görür.
 */
final class ConsoleLiveAgentTest extends TestCase
{
    use RefreshDatabase;

    private string $dizin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dizin = sys_get_temp_dir().'/konsol-anlik-'.bin2hex(random_bytes(4));
        mkdir($this->dizin);
        file_put_contents($this->dizin.'/SHA256SUMS', str_repeat('a', 64)."  console-agent-linux-amd64\n".str_repeat('b', 64)."  console-agent-windows-amd64.exe\n");
        config(['console.agent_dir' => $this->dizin]);

        /* İşin cihazı yalnız yedek klasörlerinden seçilir. */
        $this->app->instance(ConfigurationSource::class, new class implements ConfigurationSource
        {
            public function cihazlar(): array
            {
                return ['SW-1'];
            }

            public function yedekler(string $cihaz): array
            {
                return [];
            }

            public function icerik(string $cihaz, ?string $dosya = null): ?string
            {
                return null;
            }
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->dizin.'/SHA256SUMS');
        @rmdir($this->dizin);

        parent::tearDown();
    }

    #[Test]
    public function is_acilinca_kurulur_bitince_kaldirilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = ConsolePc::create([
            'ad' => 'Linux PC', 'platform' => 'linux', 'adres' => '198.51.100.15', 'port' => 2222, 'kullanici' => 'root',
            'parola' => 'x', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => '/opt/console-agent', 'ssh_parmak_izi' => 'SHA256:abc',
        ]);
        $betikler = [];
        Process::fake(function (PendingProcess $p) use (&$betikler) {
            $betikler[] = (string) json_decode((string) $p->input, true)['script'];

            return Process::result((string) json_encode(['ok' => true, 'status' => 0, 'stdout' => base64_encode('tamam'), 'stderr' => '', 'fingerprint' => 'SHA256:abc']));
        });

        /* İş yokken hiçbir şey yapılmaz. */
        $this->artisan('console:agents')->assertSuccessful();
        $this->assertSame([], $betikler);

        /* İş bir PC'ye atanmalı. */
        $this->actingAs($yonetici)->post(route('console.store'), ['ad' => 'SW-1', 'goruntu' => $this->isGirdisi($pc, 'SW-1')['goruntu'], 'yapilandirma_turu' => 'yok'])
            ->assertSessionHasErrors('pc_id');

        $this->actingAs($yonetici)->post(route('console.store'), $this->isGirdisi($pc, 'SW-1'))
            ->assertSessionHasNoErrors();
        $is = ConsoleJob::sole();

        $this->artisan('console:agents')->assertSuccessful();
        $this->assertCount(1, $betikler);
        $this->assertStringContainsString('systemctl enable', $betikler[0]);
        $this->assertStringNotContainsString('firewall-cmd', $betikler[0], 'ajan dinlemez: güvenlik duvarına dokunulmaz');
        $this->assertTrue($pc->refresh()->ajanKuruluMu());
        $jeton = ApiToken::findOrFail($pc->jeton_id);

        /* Kurulu ve iş açık: tekrar kurulmaz. */
        $this->artisan('console:agents');
        $this->assertCount(1, $betikler);

        /* Bu PC'nin ajanı işi görür; PC'ye bağlı olmayan bir jeton görmez. */
        $sir = ApiToken::mint();
        ApiToken::create(['name' => 'elle', 'user_id' => $yonetici->id, 'token_hash' => $sir['hash'], 'prefix' => $sir['prefix'], 'abilities' => ['konsol' => ['view', 'ajan']]]);
        $this->app['auth']->forgetGuards();
        $this->withToken($sir['plain'])->getJson(route('api.v1.console.jobs'))->assertOk()->assertJsonCount(0, 'data');
        $this->app['auth']->forgetGuards();
        $this->withToken($sir['plain'])->postJson(route('api.v1.console.event', $is), ['stage' => 'bilgi', 'message' => 'x'])->assertStatus(409);

        /* İş biter; bekleme süresi dolmadan kaldırılmaz, dolunca kaldırılır. */
        $is->update(['durum' => 'tamam']);
        app(ApiTokenContext::class)->clear();
        $this->artisan('console:agents');
        $this->assertCount(1, $betikler, 'ajan son adımı bildirebilsin diye bir dakika beklenir');

        $this->travel(2)->minutes();
        $this->artisan('console:agents');
        $this->assertCount(2, $betikler);
        $this->assertStringContainsString('systemctl disable --now console-agent', $betikler[1]);
        $this->assertStringContainsString('rm -rf "$d"', $betikler[1]);
        $this->assertFalse($pc->refresh()->ajanKuruluMu());
        $this->assertNotNull($jeton->refresh()->revoked_at);
    }

    /** @return array<string, mixed> */
    private function isGirdisi(ConsolePc $pc, string $ad): array
    {
        return [
            'pc_id' => $pc->id, 'ad' => $ad, 'yapilandirma_turu' => 'yok',
            'goruntu' => (string) JunosImage::query()->where('model', 'EX-2200')->value('id'),
        ];
    }
}
