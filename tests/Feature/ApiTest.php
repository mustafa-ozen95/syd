<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\Api\ApiDocumentation;
use App\Services\Backup\BackupStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API (kardeş panellerdeki düzen): jeton sahibi adına konuşur ve onu AŞAMAZ; indirmek
 * ayrı yetki; yazma ucu yok; sır yalnız bir kez görünür.
 */
final class ApiTest extends TestCase
{
    use RefreshDatabase;

    private string $kok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kok = sys_get_temp_dir().'/syd-api-'.bin2hex(random_bytes(4));
        mkdir($this->kok.'/SW-A', 0755, true);
        file_put_contents($this->kok.'/SW-A/sw-a_juniper.conf.gz_20260115_090000', 'yapilandirma');
        file_put_contents($this->kok.'/gizli.txt', 'kok');
        config(['backup.root' => $this->kok]);
        $this->app->forgetInstance(BackupStore::class);
    }

    protected function tearDown(): void
    {
        @unlink($this->kok.'/SW-A/sw-a_juniper.conf.gz_20260115_090000');
        @unlink($this->kok.'/gizli.txt');
        @rmdir($this->kok.'/SW-A');
        @rmdir($this->kok);

        parent::tearDown();
    }

    /** @param  array<string, list<string>>  $yetkiler */
    private function jeton(User $sahip, array $yetkiler, array $ek = []): string
    {
        $sir = ApiToken::mint();
        ApiToken::create([
            'name' => 'sinama', 'user_id' => $sahip->id, 'token_hash' => $sir['hash'],
            'prefix' => $sir['prefix'], 'abilities' => $yetkiler, ...$ek,
        ]);

        return $sir['plain'];
    }

    private function istek(string $jeton, string $rota, array $p = []): TestResponse
    {
        /* Her istek kendi jetonuyla: koruyucunun önbelleği istekler arasında taşınmasın. */
        $this->app['auth']->forgetGuards();

        return $this->withToken($jeton)->getJson(route($rota, $p));
    }

    #[Test]
    public function jetonsuz_ve_oturum_cereziyle_girilmez(): void
    {
        $this->getJson(route('api.v1.devices.index'))->assertUnauthorized();
        $this->actingAs(User::factory()->admin()->create())->getJson(route('api.v1.devices.index'))->assertUnauthorized();
    }

    #[Test]
    public function liste_gorulur_indirme_ayri_yetki(): void
    {
        $sahip = User::factory()->rol('network')->create();
        $jeton = $this->jeton($sahip, ['yedek' => ['view']]);

        $this->istek($jeton, 'api.v1.devices.index')->assertOk()->assertJsonPath('data.0.ad', 'SW-A');
        $this->istek($jeton, 'api.v1.devices.backups', ['device' => 'SW-A'])->assertOk()
            ->assertJsonPath('data.0.ad', 'sw-a_juniper.conf.gz_20260115_090000')
            ->assertJsonMissingPath('data.0.icerik');
        $this->istek($jeton, 'api.v1.devices.download', ['device' => 'SW-A', 'file' => 'sw-a_juniper.conf.gz_20260115_090000'])
            ->assertForbidden();

        $indiren = $this->jeton($sahip, ['yedek' => ['view', 'download']]);
        $this->istek($indiren, 'api.v1.devices.download', ['device' => 'SW-A', 'file' => 'sw-a_juniper.conf.gz_20260115_090000'])
            ->assertOk();
        $this->assertStringContainsString('API · sinama', (string) ActivityLog::where('tur', 'yedek_indirildi')->latest('id')->value('aciklama'));
    }

    #[Test]
    public function jeton_sahibini_asamaz_yonetici_jetonu_da_listesiyle_sinirli(): void
    {
        $teknik = User::factory()->rol('teknik')->create();
        $this->istek($this->jeton($teknik, ['yedek' => ['view']]), 'api.v1.devices.index')->assertForbidden();

        $yonetici = User::factory()->admin()->create();
        $this->istek($this->jeton($yonetici, ['junos' => ['view']]), 'api.v1.devices.index')->assertForbidden();
        $this->istek($this->jeton($yonetici, ['junos' => ['view']]), 'api.v1.junos.index')->assertOk()
            ->assertJsonPath('data.0.komut', 'install --format tftp://192.0.2.5/jinstall-ex-2200-12.3R12.4-domestic-signed.tgz');
    }

    #[Test]
    public function iptal_suresi_dolmus_adres_disi_ve_kapali_hesap_reddedilir(): void
    {
        $sahip = User::factory()->rol('network')->create();
        $y = ['yedek' => ['view']];

        $this->istek($this->jeton($sahip, $y, ['expires_at' => now()->subDay()]), 'api.v1.devices.index')->assertUnauthorized();
        $this->istek($this->jeton($sahip, $y, ['allowed_ips' => ['203.0.113.0/24']]), 'api.v1.devices.index')->assertUnauthorized();
        $this->istek($this->jeton($sahip, $y, ['allowed_ips' => ['127.0.0.0/8']]), 'api.v1.devices.index')->assertOk();

        $iptal = $this->jeton($sahip, $y);
        ApiToken::latest('id')->first()->revoke();
        $this->istek($iptal, 'api.v1.devices.index')->assertUnauthorized();

        $kapali = $this->jeton($sahip, $y);
        $sahip->update(['aktif' => false]);
        $this->istek($kapali, 'api.v1.devices.index')->assertUnauthorized();
    }

    #[Test]
    public function kok_disina_ve_ic_ice_yola_cikilamaz(): void
    {
        $jeton = $this->jeton(User::factory()->rol('network')->create(), ['yedek' => ['view', 'download']]);

        $this->istek($jeton, 'api.v1.devices.backups', ['device' => '..'])->assertNotFound();
        $this->istek($jeton, 'api.v1.devices.download', ['device' => 'SW-A', 'file' => '..%2Fgizli.txt'])->assertNotFound();
        $this->istek($jeton, 'api.v1.devices.download', ['device' => '..', 'file' => 'gizli.txt'])->assertNotFound();
    }

    #[Test]
    public function yonetici_jeton_cikarir_sir_bir_kez_gorunur_ozet_saklanir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $sahip = User::factory()->rol('network')->create();

        $yanit = $this->actingAs($yonetici)->post(route('admin.api.store'), [
            'name' => 'denetim', 'user' => $sahip->id, 'lifetime_days' => 30,
            'abilities' => ['yedek' => ['view', 'uydurma'], 'yok' => ['view']],
            'allowed_ips' => "198.51.100.0/24\n192.0.2.5",
        ]);

        $token = ApiToken::sole();
        $yanit->assertRedirect(route('admin.api.show', $token))->assertSessionHas('token_plain');

        $sir = session('token_plain');
        $this->assertSame(hash('sha256', $sir), $token->token_hash);
        $this->assertSame(['yedek' => ['view']], $token->abilities);
        $this->assertStringStartsWith('syd_', $sir);

        $this->actingAs($yonetici)->withSession(['token_plain' => $sir])->get(route('admin.api.show', $token))
            ->assertOk()->assertSee($sir);
        $this->actingAs($yonetici)->get(route('admin.api.show', $token))->assertOk()->assertDontSee($sir);
        $this->actingAs($yonetici)->get(route('admin.api.index'))->assertOk()->assertDontSee($sir);
        $this->actingAs($yonetici)->get(route('admin.api.docs'))->assertOk()->assertSee('/api/v1/devices');

        $this->actingAs($sahip)->get(route('admin.api.index'))->assertRedirect(route('forbidden'));
    }

    #[Test]
    public function her_uc_belgelenmis(): void
    {
        $belge = app(ApiDocumentation::class);

        $this->assertSame([], $belge->orphans());
        foreach ($belge->endpoints() as $uc) {
            $this->assertTrue($uc['documented'], $uc['name'].' belgesiz');
        }
    }
}
