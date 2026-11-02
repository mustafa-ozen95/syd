<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Services\JobOpener;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sahada kırılan yollar: kapalı PC, ölü ajan, sessiz iş, yeniden başlayan
 * ajan, aynı anda açılan iki iş.
 */
final class ConsoleRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private string $dizin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dizin = sys_get_temp_dir().'/console-agent-'.bin2hex(random_bytes(4));
        mkdir($this->dizin);
        file_put_contents($this->dizin.'/SHA256SUMS', str_repeat('a', 64)."  console-agent-linux-amd64\n".str_repeat('b', 64)."  console-agent-windows-amd64.exe\n");
        config(['console.agent_dir' => $this->dizin]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dizin.'/SHA256SUMS');
        @rmdir($this->dizin);

        parent::tearDown();
    }

    private function pc(): ConsolePc
    {
        return ConsolePc::create([
            'ad' => 'Linux PC', 'platform' => 'linux', 'adres' => '198.51.100.15', 'port' => 2222, 'kullanici' => 'root',
            'parola' => 'x', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => '/opt/console-agent', 'ssh_parmak_izi' => 'SHA256:abc',
        ]);
    }

    private function acikIs(ConsolePc $pc, User $kim, array $ek = []): ConsoleJob
    {
        return ConsoleJob::create(['pc_id' => $pc->id, 'ad' => 'sw-a', 'olusturan_id' => $kim->id, 'acik_port' => ''] + $ek);
    }

    /** @param  callable(): bool  $basarili */
    private function uzak(callable $basarili, int &$sayac): void
    {
        Process::fake(function () use ($basarili, &$sayac) {
            $sayac++;

            return Process::result((string) json_encode($basarili()
                ? ['ok' => true, 'status' => 0, 'stdout' => base64_encode('tamam'), 'stderr' => '', 'fingerprint' => 'SHA256:abc']
                : ['ok' => false, 'error' => 'timed out']));
        });
    }

    #[Test]
    public function pc_adreste_ve_formda_uuid_ile_gorunur(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();

        $this->assertStringContainsString($pc->uuid, route('console.pc.update', $pc));
        $this->assertStringNotContainsString('/pc/'.$pc->id, route('console.pc.update', $pc));
        $this->actingAs($yonetici)->get(route('console.index'))->assertOk()
            ->assertSee('<option value="'.$pc->uuid.'"', false)
            ->assertSee('console-pc-'.$pc->uuid, false)
            ->assertDontSee('console-pc-'.$pc->id.'"', false);
    }

    #[Test]
    public function ayni_pcye_ikinci_acik_is_acilamaz(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        /* İstek denetimini geçmiş ikinci operatör: o arada ilki işi açmış. */
        $this->acikIs($pc, $yonetici);

        $this->expectException(ValidationException::class);
        app(JobOpener::class)->ac($pc, ['pc_id' => $pc->id, 'ad' => 'sw-b', 'olusturan_id' => $yonetici->id, 'acik_port' => '']);
    }

    #[Test]
    public function ulasilamayan_pcye_jeton_uretilmez_ve_deneme_seyreklesir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        $this->acikIs($pc, $yonetici);
        $sayac = 0;
        $this->uzak(fn (): bool => false, $sayac);

        $this->artisan('console:agents')->assertSuccessful();
        $this->assertSame(1, $sayac, 'yalnız bağlantı sınandı');
        $this->assertSame(0, ApiToken::query()->count(), 'ulaşılamayan PC için jeton üretilmez');
        $this->assertSame(1, $pc->refresh()->ajan_deneme_sayisi);

        /* Bir dakika dolmadan yeniden denenmez; sonra denenir, aralık iki dakikaya çıkar. */
        $this->artisan('console:agents');
        $this->assertSame(1, $sayac);
        $this->travel(61)->seconds();
        $this->artisan('console:agents');
        $this->assertSame(2, $sayac);
        $this->assertTrue($pc->refresh()->ajan_sonraki_deneme_at->between(now()->addSeconds(110), now()->addSeconds(130)));

        /* PC açılınca kurulur, sayaç sıfırlanır. */
        $this->uzak(fn (): bool => true, $sayac);
        $this->travel(3)->minutes();
        $this->artisan('console:agents');
        $this->assertTrue($pc->refresh()->ajanKuruluMu());
        $this->assertSame([0, null], [$pc->ajan_deneme_sayisi, $pc->ajan_sonraki_deneme_at]);
    }

    #[Test]
    public function panele_gelmeyen_ajan_yeniden_kurulur(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        $this->acikIs($pc, $yonetici);
        $sayac = 0;
        $this->uzak(fn (): bool => true, $sayac);

        $this->artisan('console:agents');
        $eski = ApiToken::findOrFail($pc->refresh()->jeton_id);
        $eski->forceFill(['last_used_at' => now()])->save();

        /* Ajan çalışıyor: dokunulmaz. */
        $this->travel(3)->minutes();
        $eski->forceFill(['last_used_at' => now()])->save();
        $sayac = 0;
        $this->artisan('console:agents');
        $this->assertSame(0, $sayac);

        /* Ajan sustu: yeniden kurulur, eski jeton iptal edilir. */
        $this->travel(10)->minutes();
        $this->artisan('console:agents');
        $this->assertSame(2, $sayac, 'sınama + kurulum');
        $this->assertNotSame($eski->id, $pc->refresh()->jeton_id);
        $this->assertNotNull($eski->refresh()->revoked_at);
    }

    #[Test]
    public function uzun_suredir_sessiz_is_hata_ile_kapanir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        $is = $this->acikIs($pc, $yonetici, ['durum' => 'calisiyor', 'asama' => 'kurulum']);
        $sayac = 0;
        $this->uzak(fn (): bool => false, $sayac);

        $this->travel((int) config('console.job_idle_minutes') + 1)->minutes();
        $this->artisan('console:agents');

        $is->refresh();
        $this->assertSame('hata', $is->durum);
        $this->assertStringContainsString('Zaman aşımı', (string) $is->son_mesaj);
    }

    #[Test]
    public function yeniden_baslayan_ajan_kaldigi_asamayi_alir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        $is = $this->acikIs($pc, $yonetici);
        $sir = ApiToken::mint();
        $jeton = ApiToken::create(['name' => 'Konsol ajanı', 'user_id' => $yonetici->id, 'token_hash' => $sir['hash'],
            'prefix' => $sir['prefix'], 'abilities' => ['konsol' => ['view', 'ajan']]]);
        $pc->forceFill(['jeton_id' => $jeton->id])->save();

        /* İlk alış: devam aşaması yok. */
        $this->withToken($sir['plain'])->getJson(route('api.v1.console.jobs'))->assertOk()->assertJsonPath('data.0.resume_stage', '');
        $this->app['auth']->forgetGuards();
        $this->withToken($sir['plain'])->postJson(route('api.v1.console.event', $is), ['stage' => 'basladi'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($sir['plain'])->postJson(route('api.v1.console.event', $is), ['stage' => 'kurulum', 'message' => 'Kurulum başladı'])->assertOk();

        /* Ajan yeniden başladı: aşamayı alır; "basladi" aşamayı silmez. */
        $this->app['auth']->forgetGuards();
        $this->withToken($sir['plain'])->getJson(route('api.v1.console.jobs'))->assertJsonPath('data.0.resume_stage', 'kurulum');
        $this->app['auth']->forgetGuards();
        $this->withToken($sir['plain'])->postJson(route('api.v1.console.event', $is), ['stage' => 'basladi'])->assertOk();
        $this->assertSame(['calisiyor', 'kurulum'], [$is->refresh()->durum, $is->asama]);
    }

    #[Test]
    public function eski_iptal_ajan_jetonlari_silinir_elle_acilanlar_kalir(): void
    {
        $sahip = User::factory()->admin()->create();
        $jeton = function (string $ad, ?\DateTimeInterface $iptal) use ($sahip): ApiToken {
            $sir = ApiToken::mint();
            $t = ApiToken::create(['name' => $ad, 'user_id' => $sahip->id, 'token_hash' => $sir['hash'], 'prefix' => $sir['prefix'],
                'abilities' => ['konsol' => ['view']]]);
            $t->forceFill(['revoked_at' => $iptal])->save();

            return $t;
        };
        $eski = $jeton('Konsol ajanı · PC (198.51.100.1)', now()->subDays(8));
        $yeni = $jeton('Konsol ajanı · PC (198.51.100.1)', now()->subDays(1));
        $elle = $jeton('Entegrasyon', now()->subDays(30));
        Process::fake();

        $this->artisan('console:agents')->assertSuccessful();

        $this->assertNull(ApiToken::find($eski->id), '7 günden eski iptal ajan jetonu silinir');
        $this->assertNotNull(ApiToken::find($yeni->id));
        $this->assertNotNull(ApiToken::find($elle->id), 'elle açılan jeton silinmez');
    }
}
