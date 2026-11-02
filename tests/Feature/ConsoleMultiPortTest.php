<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Services\JobOpener;
use App\Models\ApiToken;
use App\Models\JunosImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Bir PC'ye birden çok konsol kablosu: her portta aynı anda bir iş. */
final class ConsoleMultiPortTest extends TestCase
{
    use RefreshDatabase;

    private function pc(array $portlar): ConsolePc
    {
        return ConsolePc::create([
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'port' => 5985, 'kullanici' => 'OPERATÖR',
            'parola' => 'x', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => 'C:\\Console-Agent',
            'seri_port' => $portlar[0] ?? '', 'seri_portlar' => $portlar,
        ]);
    }

    /** @return array<string, mixed> */
    private function is(ConsolePc $pc, string $port, string $ip = ''): array
    {
        return ['pc' => $pc->uuid, 'seri_port' => $port, 'ad' => '', 'cihaz_ip' => $ip,
            'goruntu' => (string) JunosImage::query()->where('model', 'EX-2200')->value('id')];
    }

    #[Test]
    public function farkli_portlarda_ayni_anda_is_acilir_ayni_porta_acilmaz(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc(['COM3', 'COM9']);

        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM3'))->assertSessionHasNoErrors();
        /* İkinci switch: başka port, başka loader adresi. */
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM9', '198.51.100.121/24'))->assertSessionHasNoErrors();
        $this->assertSame(2, ConsoleJob::query()->acik()->count());

        /* Aynı porta ikinci iş olmaz; aynı loader adresi de. */
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM3', '198.51.100.122/24'))->assertSessionHasErrors('pc');
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM9', '198.51.100.121/24'))->assertSessionHasErrors('cihaz_ip');

        /* Çok portlu PC'de port seçmek şart; tanımsız port olmaz. */
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, '', '198.51.100.123/24'))->assertSessionHasErrors('seri_port');
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM7', '198.51.100.123/24'))->assertSessionHasErrors('seri_port');

        /* İş kapanınca port boşalır. */
        ConsoleJob::query()->where('seri_port', 'COM3')->sole()->kapat('tamam', null);
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM3', '198.51.100.124/24'))->assertSessionHasNoErrors();

        /* Ajan her işi kendi portuyla alır. */
        $sir = ApiToken::mint();
        $jeton = ApiToken::create(['name' => 'Konsol ajanı', 'user_id' => $yonetici->id, 'token_hash' => $sir['hash'],
            'prefix' => $sir['prefix'], 'abilities' => ['konsol' => ['view', 'ajan']]]);
        $pc->forceFill(['jeton_id' => $jeton->id])->save();
        $this->app['auth']->forgetGuards();
        $portlar = collect($this->withToken($sir['plain'])->getJson(route('api.v1.console.jobs'))->assertOk()->json('data'))->pluck('serial_port')->sort()->values()->all();
        $this->assertSame(['COM3', 'COM9'], $portlar);
    }

    #[Test]
    public function tek_portlu_pcde_otomatik_o_porttur(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc(['COM9']);

        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, ''))->assertSessionHasNoErrors();
        $this->assertSame(['COM9', 'COM9'], [ConsoleJob::sole()->seri_port, ConsoleJob::sole()->acik_port]);
        /* "Otomatik" ile açılan iş de o portu tutar. */
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM9', '198.51.100.121/24'))->assertSessionHasErrors('pc');
    }

    #[Test]
    public function veritabani_da_ayni_porta_ikinci_acik_isi_reddeder(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc(['COM3']);
        ConsoleJob::create(['pc_id' => $pc->id, 'ad' => 'a', 'seri_port' => 'COM3', 'acik_port' => 'COM3', 'olusturan_id' => $yonetici->id]);

        $this->expectException(ValidationException::class);
        /* Ön denetimi atlatmış bir yarış: kilit yerine unique yakalar. */
        app(JobOpener::class)->ac($this->pc(['COM4']), ['pc_id' => $pc->id, 'ad' => 'b', 'seri_port' => 'COM3', 'acik_port' => 'COM3', 'olusturan_id' => $yonetici->id]);
    }

    #[Test]
    public function portlar_pcden_kablo_kimligiyle_algilanir_elle_yazilmaz(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc(['COM9']);
        $ftdi = 'FTDIBUS\\VID_0403+PID_6001+A50285BIA\\0000';
        $cikti = "Bağlantı tamam\n";
        Process::fake(function () use (&$cikti) {
            return Process::result((string) json_encode(['ok' => true, 'status' => 0, 'stdout' => base64_encode($cikti), 'stderr' => '']));
        });

        /* Sına: önce bağlantı, sonra portlar (biri zararlı biçimde: alınmaz). */
        $this->actingAs($yonetici)->post(route('console.pc.islem', [$pc, 'test']));
        $cikti = "{$ftdi}|COM9|USB Serial Port\r\nUSB\\VID_067B&PID_2303\\5&2A3D6F1&0&2|COM4|Prolific USB-to-Serial Comm Port\r\nX;del C:\\|COM5|kötü\r\n";
        $this->actingAs($yonetici)->post(route('console.pc.islem', [$pc, 'status']))
            ->assertSessionHas('pcSonuc', fn (array $s): bool => in_array('COM9 · USB Serial Port', $s['durum']['portlar'], true));

        $pc->refresh();
        $this->assertSame([$ftdi, 'USB\\VID_067B&PID_2303\\5&2A3D6F1&0&2'], $pc->portlar());
        $this->assertNotNull($pc->portlar_at);

        /* İş formunda etiketle görünür, kimlikle gider. */
        $this->actingAs($yonetici)->get(route('console.index'))->assertOk()
            ->assertSee('COM9 · USB Serial Port')->assertSee(e($ftdi), false);
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, $ftdi))->assertSessionHasNoErrors();
        $this->assertSame($ftdi, ConsoleJob::sole()->seri_port);

        /* Kaydet portları silmez; formdan gelen liste yok sayılır. */
        $this->actingAs($yonetici)->put(route('console.pc.update', $pc), [
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'kullanici' => 'OPERATÖR',
            'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'seri_portlar' => 'COM3; del C:\\',
        ])->assertSessionHasNoErrors();
        $this->assertCount(2, $pc->refresh()->portlar());
    }
}
