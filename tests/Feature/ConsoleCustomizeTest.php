<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Sources\SablonluKaynak;
use App\Models\ActivityLog;
use App\Models\ApiToken;
use App\Models\ConfigSablonu;
use App\Models\JunosImage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** "Yapılandırmayı özelleştir": yedeğin üstüne aynı commit'te uygulanan yama. */
final class ConsoleCustomizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(ConfigurationSource::class, new class implements ConfigurationSource
        {
            public function cihazlar(): array
            {
                return ['sw-a'];
            }

            public function yedekler(string $cihaz): array
            {
                return $cihaz === 'sw-a' ? [['dosya' => 'sw-a_1', 'zaman' => 1]] : [];
            }

            public function icerik(string $cihaz, ?string $dosya = null): ?string
            {
                return $cihaz === 'sw-a' ? (string) file_get_contents(base_path('tests/Fixtures/junos/ornek-ex.conf')) : null;
            }
        });
    }

    private function pc(): ConsolePc
    {
        return ConsolePc::create([
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'port' => 5985, 'kullanici' => 'OPERATÖR',
            'parola' => 'x', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => 'C:\\Console-Agent',
            /* Algılanmış tek konsol kablosu: iş formunda seçili gelir ("Otomatik" yok). */
            'seri_port' => 'COM3', 'seri_portlar' => ['COM3'],
        ]);
    }

    /** @return array<string, mixed> */
    private function form(ConsolePc $pc, array $oz): array
    {
        return [
            'pc' => $pc->uuid, 'ad' => 'sw-a', 'yedek_dosya' => 'sw-a_1', 'islem' => 'yedek', 'ozellestir' => '1', 'ozellestirme' => $oz,
        ];
    }

    #[Test]
    public function alanlar_yedekten_dolar(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson(route('console.fields', ['device' => 'sw-a', 'file' => 'sw-a_1']))->assertOk()
            ->assertJsonPath('alanlar.host_name', 'sw-ornek.example.test')
            ->assertJsonPath('alanlar.uplink_arayuz', 'ge-0/0/23')
            ->assertJsonPath('alanlar.re_filter', ['203.0.113.5/32']);

        $this->actingAs(User::factory()->create())->getJson(route('console.fields', ['device' => 'sw-a', 'file' => 'sw-a_1']))->assertForbidden();
    }

    #[Test]
    public function onizleme_maskeli_ve_dogrulanir(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->postJson(route('console.preview'), [
            'ad' => 'sw-a', 'yedek_dosya' => 'sw-a_1',
            'ozellestirme' => ['host_name' => 'piga', 'root_parola' => 'Yeni-Parola-1'],
        ])->assertOk()
            ->assertJsonPath('satirlar.0', 'set system host-name piga')
            ->assertJsonPath('satirlar.1', 'set system root-authentication encrypted-password "<gizli>"')
            ->assertJsonPath('ozet', 'makine adı, root parolası');

        $this->actingAs($yonetici)->postJson(route('console.preview'), [
            'ad' => 'sw-a', 'yedek_dosya' => 'sw-a_1',
            'ozellestirme' => ['koru' => "10.0.0.0/24\n300.1.1.1/24", 'ek_komutlar' => 'set system host-name x; request system halt'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['ozellestirme.koru', 'ozellestirme.ek_komutlar']);
    }

    #[Test]
    public function is_yamayla_acilir_parola_saklanmaz_ve_ajana_bir_kez_gider(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();

        $this->actingAs($yonetici)->post(route('console.store'), $this->form($pc, [
            'host_name' => 'piga', 'uplink_arayuz' => 'xe-0/1/0', 'uplink_adres' => '10.0.0.230/30', 'uplink_gecit' => '10.0.0.229',
            'admin_parola' => 'Yeni-Parola-1',
        ]))->assertSessionHasNoErrors();

        $is = ConsoleJob::sole();
        $this->assertStringContainsString('set system host-name piga', (string) $is->yama);
        $this->assertSame('xe-0/1/0', $is->uplink_arayuz);
        $this->assertSame('makine adı, uplink xe-0/1/0, admin parolası', $is->yama_ozeti);
        $this->assertStringContainsString('özelleştirildi', $is->yapilandirmaOzeti());

        /* Düz parola hiçbir yerde yok; yama veritabanında şifreli. */
        $ham = (string) DB::table('konsol_isleri')->value('yama');
        $this->assertStringNotContainsString('host-name', $ham);
        $this->assertStringNotContainsString('Yeni-Parola-1', $ham.(string) $is->yama.json_encode(ActivityLog::query()->get()->toArray()));

        $sir = ApiToken::mint();
        $jeton = ApiToken::create(['name' => 'Konsol ajanı', 'user_id' => $yonetici->id, 'token_hash' => $sir['hash'],
            'prefix' => $sir['prefix'], 'abilities' => ['konsol' => ['view', 'ajan']]]);
        $pc->forceFill(['jeton_id' => $jeton->id])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($sir['plain'])->getJson(route('api.v1.console.jobs'))
            ->assertJsonPath('data.0.patch', true)->assertJsonPath('data.0.uplink_interface', 'xe-0/1/0');
        $this->app['auth']->forgetGuards();
        $metin = (string) $this->withToken($sir['plain'])->get(route('api.v1.console.patch', $is))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/login user admin authentication encrypted-password "\$1\$/', $metin);

        /* İş kapanınca yama (parola özetleri) silinir. */
        $is->kapat('tamam', null);
        $this->assertNull($is->refresh()->yama);
        $this->app['auth']->forgetGuards();
        $this->withToken($sir['plain'])->get(route('api.v1.console.patch', $is))->assertStatus(409);
    }

    #[Test]
    public function cihaz_secilmezse_ya_da_istenmezse_yama_yok(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        $goruntu = (string) JunosImage::query()->where('model', 'EX-2200')->value('id');

        $this->actingAs($yonetici)->post(route('console.store'), [
            'pc' => $pc->uuid, 'ad' => '', 'goruntu' => $goruntu, 'ozellestir' => '1', 'ozellestirme' => ['host_name' => 'piga'],
        ])->assertSessionHasNoErrors();
        $this->assertNull(ConsoleJob::sole()->yama);
        ConsoleJob::query()->delete();

        $this->actingAs($yonetici)->post(route('console.store'), ['ozellestir' => '0'] + $this->form($pc, ['host_name' => 'piga']))
            ->assertSessionHasNoErrors();
        $this->assertNull(ConsoleJob::sole()->yama);
    }

    /**
     * Teknik: Konsol'da iş açar, yedeği listeden seçer; özelleştirme yalnız
     * config şablonlarında. Yedekler sayfasına giremez.
     */
    #[Test]
    public function ozellestirme_yetkisi_olmayan_yalniz_sablonda_ozellestirir(): void
    {
        Role::where('slug', 'teknik')->sole()->update(['yetkiler' => ['konsol' => ['view', 'create', 'ajan']]]);
        $teknik = User::factory()->rol('teknik')->create();
        $pc = $this->pc();
        ConfigSablonu::create(['ad' => 'Sıfır Config', 'dosya' => 'sifir.conf', 'metin' => (string) file_get_contents(base_path('tests/Fixtures/junos/ornek-ex.conf')), 'boyut' => 1]);
        $this->app->instance(ConfigurationSource::class, new SablonluKaynak($this->app->make(ConfigurationSource::class)));

        /* Cihaz yedeği: seçip yükleyebilir ama özelleştiremez; alanlar gösterilmez. */
        $this->actingAs($teknik)->post(route('console.store'), $this->form($pc, ['host_name' => 'x']))->assertSessionHasErrors('ozellestir');
        $this->actingAs($teknik)->getJson(route('console.fields', ['device' => 'sw-a', 'file' => 'sw-a_1']))->assertOk()->assertJsonPath('alanlar', null);
        $this->actingAs($teknik)->post(route('console.store'), ['ozellestir' => '0'] + $this->form($pc, []))->assertSessionHasNoErrors();
        ConsoleJob::query()->delete();

        /* Bilgisayardan yüklenen yedekte de özelleştiremez. */
        $this->actingAs($teknik)->post(route('console.store'), [
            'pc' => $pc->uuid, 'islem' => 'yedek', 'ad' => '', 'ozellestir' => '1', 'ozellestirme' => ['host_name' => 'x'],
            'yedek_yukle' => UploadedFile::fake()->createWithContent('a.conf', (string) file_get_contents(base_path('tests/Fixtures/junos/ornek-ex.conf'))),
        ])->assertSessionHasErrors('ozellestir');

        /* Config şablonunda özelleştirir. */
        $this->actingAs($teknik)->getJson(route('console.fields', ['device' => 'Sıfır Config', 'file' => 'sifir.conf']))->assertJsonPath('alanlar.host_name', 'sw-ornek.example.test');
        $this->actingAs($teknik)->post(route('console.store'), [
            'pc' => $pc->uuid, 'ad' => 'Sıfır Config', 'yedek_dosya' => 'sifir.conf', 'islem' => 'yedek', 'ozellestir' => '1',
            'ozellestirme' => ['host_name' => 'piga', 'root_parola' => 'Yeni-Parola-1'],
        ])->assertSessionHasNoErrors();
        $this->assertStringContainsString('set system host-name piga', (string) ConsoleJob::sole()->yama);

        /* Yedekleri ne görür ne indirir; ana sayfa onu "yetki yok" yerine konsola götürür. */
        $this->actingAs($teknik)->get(route('backup.index'))->assertRedirect(route('console.index'));
        $this->actingAs($teknik)->get(route('backup.folder', ['path' => 'sw-a']))->assertRedirect(route('forbidden'));
    }
}
