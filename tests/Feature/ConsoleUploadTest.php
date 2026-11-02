<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Panelin listeleri dışında bilgisayardan seçilen yedek ve Junos dosyası. */
final class ConsoleUploadTest extends TestCase
{
    use RefreshDatabase;

    private function pc(): ConsolePc
    {
        return ConsolePc::create([
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'port' => 5985, 'kullanici' => 'OPERATÖR',
            'parola' => 'x', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => 'C:\\Console-Agent',
        ]);
    }

    private function yedek(string $ad = 'musteri.conf', bool $gz = false): UploadedFile
    {
        $metin = (string) file_get_contents(base_path('tests/Fixtures/junos/ornek-ex.conf'));

        return UploadedFile::fake()->createWithContent($ad, $gz ? (string) gzencode($metin) : $metin);
    }

    private function ajanJetonu(ConsolePc $pc, User $sahip): string
    {
        $sir = ApiToken::mint();
        $jeton = ApiToken::create(['name' => 'Konsol ajanı', 'user_id' => $sahip->id, 'token_hash' => $sir['hash'],
            'prefix' => $sir['prefix'], 'abilities' => ['konsol' => ['view', 'ajan']]]);
        $pc->forceFill(['jeton_id' => $jeton->id])->save();
        $this->app['auth']->forgetGuards();

        return $sir['plain'];
    }

    #[Test]
    public function bilgisayardan_yedek_yuklenir_sifreli_saklanir_ve_ajana_gider(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();

        $this->actingAs($yonetici)->post(route('console.store'), [
            'pc' => $pc->uuid, 'islem' => 'yedek', 'ad' => '', 'yedek_yukle' => $this->yedek('musteri-sw.conf.gz', gz: true),
            'ozellestir' => '1', 'ozellestirme' => ['host_name' => 'musteri-sw'],
        ])->assertSessionHasNoErrors();

        $is = ConsoleJob::sole();
        $this->assertSame(['dosya', 'musteri-sw.conf.gz', 'musteri-sw.conf'], [$is->yapilandirma_turu, $is->yedek_cihaz, $is->ad]);
        $this->assertStringContainsString('Bilgisayardan yüklenen yedek', $is->yapilandirmaOzeti());
        $this->assertStringNotContainsString('host-name', (string) DB::table('konsol_isleri')->value('yedek_metni'), 'yedek şifreli saklanır');
        $this->assertStringContainsString('set system host-name musteri-sw', (string) $is->yama, 'özelleştirme yüklenen yedekte de çalışır');

        $jeton = $this->ajanJetonu($pc, $yonetici);
        $metin = (string) $this->withToken($jeton)->get(route('api.v1.console.configuration', $is))->assertOk()->getContent();
        $this->assertStringContainsString('host-name sw-ornek.example.test;', $metin, 'gz açılmış, düz metin gider');

        $is->kapat('tamam', null);
        $this->assertNull($is->refresh()->yedek_metni);
    }

    #[Test]
    public function junos_yapilandirmasi_olmayan_dosya_reddedilir(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('console.store'), [
            'pc' => $this->pc()->uuid, 'islem' => 'yedek', 'ad' => '',
            'yedek_yukle' => UploadedFile::fake()->createWithContent('fatura.txt', 'merhaba dünya'),
        ])->assertSessionHasErrors('yedek_yukle');
        $this->assertSame(0, ConsoleJob::query()->count());
    }

    #[Test]
    public function bilgisayardan_junos_dosyasi_tftpye_konur_is_bitince_silinir(): void
    {
        Storage::fake('junos');
        Process::fake();
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc();

        /* Ad loader komutuna yazılır: güvensiz ad reddedilir. */
        $this->actingAs($yonetici)->post(route('console.store'), [
            'pc' => $pc->uuid, 'ad' => '', 'goruntu_yukle' => UploadedFile::fake()->create('kötü ad;rm.tgz', 10),
        ])->assertSessionHasErrors('goruntu_yukle');

        $this->actingAs($yonetici)->post(route('console.store'), [
            'pc' => $pc->uuid, 'ad' => '', 'goruntu_yukle' => UploadedFile::fake()->create('jinstall-ex-3300-15.1R7.9-domestic-signed.tgz', 2048),
        ])->assertSessionHasNoErrors();

        $is = ConsoleJob::sole();
        $this->assertNotNull($is->gecici_goruntu);
        Storage::disk('junos')->assertExists($is->gecici_goruntu);
        $this->assertStringEndsWith('/'.$is->gecici_goruntu, (string) $is->goruntu_adres);
        $this->assertSame('15.1R7.9', $is->beklenen_surum);
        $this->assertSame('Bilgisayardan · jinstall-ex-3300-15.1R7.9-domestic-signed.tgz', $is->goruntu_etiket);

        /* İş kapanınca, bekleme süresi dolunca dosya silinir. */
        $dosya = $is->gecici_goruntu;
        $is->kapat('tamam', null);
        $this->travel(2)->minutes();
        $this->artisan('console:agents')->assertSuccessful();
        Storage::disk('junos')->assertMissing($dosya);
        $this->assertNull($is->refresh()->gecici_goruntu);
    }

    #[Test]
    public function onizleme_yuklenen_yedekle_calisir(): void
    {
        $this->actingAs(User::factory()->admin()->create())->post(route('console.preview'), [
            'yedek_yukle' => $this->yedek(), 'ozellestirme' => ['host_name' => 'yeni-ad'],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('satirlar.0', 'set system host-name yeni-ad');

        $this->actingAs(User::factory()->admin()->create())->post(route('console.fields'), ['yedek_yukle' => $this->yedek()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('alanlar.uplink_arayuz', 'ge-0/0/23');
    }
}
