<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Services\AgentJob;
use App\ConsoleModule\Sources\TftpProgress;
use App\Models\ApiToken;
use App\Models\JunosImage;
use App\Models\User;
use App\Services\Api\ApiTokenContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Konsoldan kurulum: iş açılır, ajan iş tanımını
 * alır, adımları ve konsol çıktısını aktarır, panelden yazılanları alır.
 */
final class ConsoleTest extends TestCase
{
    use RefreshDatabase;

    private function pc(): ConsolePc
    {
        return ConsolePc::create([
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'port' => 5985, 'kullanici' => 'OPERATÖR',
            'parola' => 'x', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => 'C:\\Console-Agent',
        ]);
    }

    /** PC'ye kurulmuş ajanın jetonu. */
    private function ajan(ConsolePc $pc, User $sahip): string
    {
        $sir = ApiToken::mint();
        $jeton = ApiToken::create(['name' => 'Konsol ajanı', 'user_id' => $sahip->id, 'token_hash' => $sir['hash'],
            'prefix' => $sir['prefix'], 'abilities' => ['konsol' => ['view', 'ajan']]]);
        $pc->forceFill(['jeton_id' => $jeton->id])->save();

        return $sir['plain'];
    }

    private function yedekler(): void
    {
        $this->app->instance(ConfigurationSource::class, new class implements ConfigurationSource
        {
            public function cihazlar(): array
            {
                return ['sw-a', 'sw-b', 'sw-c', 'sw-yeni'];
            }

            public function yedekler(string $cihaz): array
            {
                return $cihaz === 'sw-a' ? [['dosya' => 'sw-a_2', 'zaman' => 2], ['dosya' => 'sw-a_1', 'zaman' => 1]] : [];
            }

            public function icerik(string $cihaz, ?string $dosya = null): ?string
            {
                return $cihaz === 'sw-a' ? "system {\n    host-name sw-a; /* ".($dosya ?? 'sw-a_2')." */\n}\n" : null;
            }
        });
    }

    #[Test]
    public function kurulum_isi_ajana_loader_komutlariyla_gider(): void
    {
        $this->yedekler();
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        $goruntu = JunosImage::query()->where('model', 'EX-2200')->firstOrFail();

        $this->actingAs($yonetici)->post(route('console.store'), [
            'pc_id' => $pc->id, 'ad' => 'sw-a', 'goruntu' => (string) $goruntu->id,
            'yapilandirma_turu' => 'son_yedek', 'yedek_cihaz' => 'sw-a', 'root_parola' => 'eski-root',
        ])->assertSessionHasNoErrors();

        $is = ConsoleJob::sole();
        $this->assertSame('12.3R12.4', $is->beklenen_surum);
        $this->assertStringNotContainsString('eski-root', (string) ConsoleJob::query()->getQuery()->value('root_parola'));

        /* Bir PC'de tek açık iş: kablo bir tane. */
        $this->actingAs($yonetici)->post(route('console.store'), ['pc_id' => $pc->id, 'ad' => 'sw-b'])
            ->assertSessionHasErrors('pc_id');

        $jeton = $this->ajan($pc, $yonetici);
        $this->app['auth']->forgetGuards();
        $veri = $this->withToken($jeton)->getJson(route('api.v1.console.jobs'))->assertOk()->json('data.0');

        $this->assertSame([
            'set ipaddr=198.51.100.120', 'set netmask=255.255.255.0', 'set gatewayip=198.51.100.1',
        ], $veri['loader_commands']);
        $this->assertSame('install --format tftp://192.0.2.5/jinstall-ex-2200-12.3R12.4-domestic-signed.tgz', $veri['install_command']);
        $this->assertSame('198.51.100.120', $veri['device_ip']);
        $this->assertSame('eski-root', $veri['password']);
        $this->assertTrue($veri['configuration']);

        /* Yedek metni yalnız açık işte, en son yedek. */
        $this->app['auth']->forgetGuards();
        $metin = $this->withToken($jeton)->get(route('api.v1.console.configuration', $is))->assertOk()->getContent();
        $this->assertStringContainsString('host-name sw-a; /* sw-a_2 */', (string) $metin);

        /* Adımlar; "tamam" işi kapatır ve root parolasını siler. */
        foreach (['basladi' => 'calisiyor', 'loader' => 'calisiyor', 'tamam' => 'tamam'] as $asama => $durum) {
            $this->app['auth']->forgetGuards();
            $this->withToken($jeton)->postJson(route('api.v1.console.event', $is), ['stage' => $asama, 'message' => "m-{$asama}"])->assertOk();
            $this->assertSame($durum, $is->refresh()->durum);
        }
        $this->assertNull($is->root_parola);
        $this->assertSame((int) ApiToken::query()->where('token_hash', hash('sha256', $jeton))->value('id'), (int) $is->ajan_token_id);

        /* Kapalı işe yazılan her şey 409: ajan durur. */
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson(route('api.v1.console.output', $is), ['text' => 'x'])->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->get(route('api.v1.console.configuration', $is))->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->getJson(route('api.v1.console.jobs'))->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function konsol_ciktisi_ham_saklanir_panelden_yazilan_ajana_bir_kez_gider(): void
    {
        $this->yedekler();
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        $this->actingAs($yonetici)->post(route('console.store'), $this->isGirdisi($pc, 'sw-c'))
            ->assertSessionHasNoErrors();
        $is = ConsoleJob::sole();
        $jeton = $this->ajan($pc, $yonetici);

        /* Panelden: bir satır ve tek boşluk (boşluk kırpılmamalı: loader'a giriş yolu). */
        $this->actingAs($yonetici)->postJson(route('console.input', $is), ['satir' => 'show version'])->assertOk();
        $this->actingAs($yonetici)->postJson(route('console.input', $is), ['tus' => 'bosluk'])->assertOk();
        $this->actingAs($yonetici)->postJson(route('console.input', $is), ['tus' => 'ctrl-c'])->assertOk();
        $this->actingAs($yonetici)->postJson(route('console.input', $is), ['tus' => 'yok'])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        app(ApiTokenContext::class)->clear();
        $this->withToken($jeton)->postJson(route('api.v1.console.output', $is), ['text' => "\r\nlogin: "])
            ->assertOk()->assertExactJson(['inputs' => ["show version\r", ' ', "\x03"], 'cancelled' => false]);

        /* İkinci aktarımda aynı girdiler yeniden gelmez; boş çıktı da kabul. */
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson(route('api.v1.console.output', $is), ['text' => ''])
            ->assertOk()->assertExactJson(['inputs' => [], 'cancelled' => false]);

        $this->assertSame("\r\nlogin: ", $is->ciktilar()->sole()->metin, 'istem olduğu gibi saklanır');

        /* Canlı ekran yoklaması: yalnız verilen parçadan sonrası. (Sınamada jeton bağlamı istekler arasında kalıyor.) */
        app(ApiTokenContext::class)->clear();
        $akis = $this->actingAs($yonetici)->getJson(route('console.stream', $is))->assertOk()->json();
        $this->assertSame("\r\nlogin: ", $akis['metin']);
        $this->assertTrue($akis['acik']);
        $this->actingAs($yonetici)->getJson(route('console.stream', $is).'?after='.$akis['son'])->assertOk()->assertJsonPath('metin', '');

        $this->actingAs($yonetici)->get(route('console.show', $is))->assertOk()->assertSee('data-console-screen', false);
        $this->actingAs($yonetici)->get(route('console.log', $is))->assertOk()->assertStreamedContent("\r\nlogin: ");

        /* Panelden kapatılır; ajan sonraki aktarımda 409 alıp durur. */
        $this->actingAs($yonetici)->put(route('console.close', $is))->assertRedirect(route('console.show', $is));
        $this->assertSame('iptal', $is->refresh()->durum);
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson(route('api.v1.console.output', $is), ['text' => 'x'])->assertStatus(409);
        app(ApiTokenContext::class)->clear();
        $this->actingAs($yonetici)->postJson(route('console.input', $is), ['satir' => 'x'])->assertStatus(409);
    }

    #[Test]
    public function baska_pcnin_ajani_isi_goremez(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();
        $baska = $this->pc();
        $is = ConsoleJob::create(['pc_id' => $pc->id, 'ad' => 'sw-d', 'olusturan_id' => $yonetici->id]);
        $jeton = $this->ajan($baska, $yonetici);

        $this->withToken($jeton)->getJson(route('api.v1.console.jobs'))->assertOk()->assertJsonCount(0, 'data');
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson(route('api.v1.console.output', $is), ['text' => 'x'])->assertStatus(409);
        $this->app['auth']->forgetGuards();
        $this->withToken($jeton)->postJson(route('api.v1.console.event', $is), ['stage' => 'tamam'])->assertStatus(409);
        $this->assertSame('bekliyor', $is->refresh()->durum);
    }

    #[Test]
    public function yedek_secimi_cihaza_gore(): void
    {
        $this->yedekler();
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();

        /* Seçilen cihazın yedekleri, en yenisi önce; yetkisiz kişi göremez. */
        $this->actingAs($yonetici)->getJson(route('console.backups', ['device' => 'sw-a']))->assertOk()
            ->assertJsonPath('yedekler.0.dosya', 'sw-a_2')->assertJsonPath('yedekler.1.dosya', 'sw-a_1');
        $this->actingAs($yonetici)->getJson(route('console.backups', ['device' => '../sw-a']))->assertOk()->assertJsonPath('yedekler', []);
        $this->actingAs(User::factory()->create())->getJson(route('console.backups', ['device' => 'sw-a']))->assertForbidden();

        /* "En son yedek" Cihaz alanındaki adın yedeğidir; ayrı cihaz seçilmez. */
        $this->actingAs($yonetici)->post(route('console.store'), ['yapilandirma_turu' => 'son_yedek'] + $this->isGirdisi($pc, 'sw-yok'))
            ->assertSessionHasErrors('ad');
        $this->actingAs($yonetici)->get(route('console.index'))->assertOk()
            ->assertSee('<option value="sw-a"', false)->assertDontSee('list="console-devices"', false)
            /* "Listede yok" boş değer: tarayıcı reddetmesin. */
            ->assertSee('name="ad" data-console-device', false);
        $this->actingAs($yonetici)->post(route('console.store'), ['yapilandirma_turu' => 'son_yedek'] + $this->isGirdisi($pc, 'sw-a'))
            ->assertSessionHasNoErrors();
        $is = ConsoleJob::sole();
        $this->assertSame(['sw-a', null], [$is->yedek_cihaz, $is->yedek_dosya]);
        $is->delete();

        /* "Seçilen yedek": dosya o cihazın listesinden olmalı. */
        $secilen = ['yapilandirma_turu' => 'yedek', 'yedek_cihaz' => 'sw-a'] + $this->isGirdisi($pc, 'sw-yeni');
        $this->actingAs($yonetici)->post(route('console.store'), ['yedek_dosya' => 'sw-a_9'] + $secilen)->assertSessionHasErrors('yedek_dosya');
        $this->actingAs($yonetici)->post(route('console.store'), ['yedek_dosya' => 'sw-a_1'] + $secilen)->assertSessionHasNoErrors();
        $this->assertSame(['sw-a', 'sw-a_1'], [ConsoleJob::sole()->yedek_cihaz, ConsoleJob::sole()->yedek_dosya]);
    }

    #[Test]
    public function cihaz_bos_olabilir_adres_iste_secilir(): void
    {
        $this->yedekler();
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();

        /* "En son yedek" cihazsız olmaz; yedeksiz yeni cihaz olur. */
        $this->actingAs($yonetici)->post(route('console.store'), ['yapilandirma_turu' => 'son_yedek'] + $this->isGirdisi($pc, ''))
            ->assertSessionHasErrors('ad');
        $this->actingAs($yonetici)->post(route('console.store'), ['adres_turu' => 'dhcp'] + $this->isGirdisi($pc, ''))
            ->assertSessionHasNoErrors();
        $is = ConsoleJob::sole();
        $this->assertSame(['Yeni cihaz', 'dhcp', null], [$is->ad, $is->adres_turu, $is->cihaz_ip]);

        $jeton = $this->ajan($pc, $yonetici);
        $this->app['auth']->forgetGuards();
        $veri = $this->withToken($jeton)->getJson(route('api.v1.console.jobs'))->assertOk()->json('data.0');
        $this->assertSame([], $veri['loader_commands'], 'DHCP: adres satırı yazılmaz');
        $this->assertSame('', $veri['device_ip'], 'DHCP: ping sınaması yapılmaz');
    }

    #[Test]
    public function sabit_adres_iste_degistirilebilir(): void
    {
        $this->yedekler();
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();

        $this->actingAs($yonetici)->post(route('console.store'), ['cihaz_ip' => '198.51.100.150/23', 'gecit' => '198.51.100.254'] + $this->isGirdisi($pc, 'sw-a'))
            ->assertSessionHasNoErrors();
        $jeton = $this->ajan($pc, $yonetici);
        $this->app['auth']->forgetGuards();
        $veri = $this->withToken($jeton)->getJson(route('api.v1.console.jobs'))->assertOk()->json('data.0');
        $this->assertSame(['set ipaddr=198.51.100.150', 'set netmask=255.255.254.0', 'set gatewayip=198.51.100.254'], $veri['loader_commands']);
        $this->assertSame('198.51.100.150', $veri['device_ip']);
    }

    #[Test]
    public function yetkisiz_kisi_konsolu_goremez_yazamaz(): void
    {
        $kisi = User::factory()->create();
        $pc = $this->pc();
        $is = ConsoleJob::create(['pc_id' => $pc->id, 'ad' => 'sw-e']);

        $this->actingAs($kisi)->get(route('console.index'))->assertRedirect(route('forbidden'));
        $this->actingAs($kisi)->getJson(route('console.stream', $is))->assertForbidden();
        $this->actingAs($kisi)->postJson(route('console.input', $is), ['satir' => 'request system zeroize'])->assertForbidden();
        $this->assertSame(0, $is->girdiler()->count());
    }

    #[Test]
    public function dosya_adindan_surum_ve_maskeden_ag_maskesi(): void
    {
        $this->assertSame('12.3R12.4', AgentJob::surum('jinstall-ex-2200-12.3R12.4-domestic-signed.tgz'));
        $this->assertSame('24.4R2.23', AgentJob::surum('junos-arm-32-24.4R2.23.tgz'));
        $this->assertSame('23.4R2-S8.7', AgentJob::surum('junos-vmhost-install-mx-x86-64-23.4R2-S8.7.tgz'));
        $this->assertSame('12.1R3', AgentJob::surum('jloader-ex-3242-12.1R3-signed.tgz'));
        $this->assertNull(AgentJob::surum('bir-dosya.tgz'));

        $pc = new ConsolePc(['cihaz_ip' => '198.51.100.120/23']);
        $this->assertSame(['198.51.100.120', '255.255.254.0'], $pc->cihazAdresi());
    }

    /** @return array<string, mixed> */
    #[Test]
    public function tftp_ilerlemesi_bu_isin_dosyasindan_okunur(): void
    {
        $this->yedekler();
        $yonetici = User::factory()->admin()->create();
        $this->actingAs($yonetici)->post(route('console.store'), $this->isGirdisi($this->pc(), 'sw-a'))->assertSessionHasNoErrors();
        $is = ConsoleJob::sole();
        $dosya = basename((string) parse_url($is->goruntu_adres, PHP_URL_PATH));
        $yol = tempnam(sys_get_temp_dir(), 'tftp');
        config(['console.tftp_state' => $yol]);
        $an = fn (int $sn) => $is->created_at->copy()->addSeconds($sn)->toRfc3339String();
        $aktarim = fn (string $ad, int $basladi, int $giden) => [
            'file' => $ad, 'size' => 1000, 'sent' => $giden, 'started' => $an($basladi), 'updated' => $an($basladi + 10), 'done' => false, 'error' => '',
        ];

        try {
            file_put_contents($yol, json_encode(['transfers' => [
                $aktarim($dosya, -60, 900),   // işten önce: sayılmaz
                $aktarim('baska.tgz', 5, 800),
                $aktarim($dosya, 5, 250),
            ]]));
            $this->assertSame(
                ['giden' => 250, 'boyut' => 1000, 'yuzde' => 25, 'hiz' => 25, 'kalan' => 30, 'bitti' => false, 'hata' => ''],
                app(TftpProgress::class)->icin($is),
            );

            file_put_contents($yol, json_encode(['transfers' => [$aktarim($dosya, -60, 900)]]));
            $this->assertNull(app(TftpProgress::class)->icin($is));
        } finally {
            @unlink($yol);
        }

        config(['console.tftp_state' => $yol]);
        $this->assertNull(app(TftpProgress::class)->icin($is), 'Dosya yoksa ilerleme yok');
    }

    private function isGirdisi(ConsolePc $pc, string $ad): array
    {
        return [
            'pc_id' => $pc->id, 'ad' => $ad, 'yapilandirma_turu' => 'yok',
            'goruntu' => (string) JunosImage::query()->where('model', 'EX-2200')->value('id'),
        ];
    }
}
