<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Models\ConsoleJob;
use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Services\ConsolePage;
use App\ConsoleModule\Services\JobOpener;
use App\Models\ApiToken;
use App\Models\JunosImage;
use App\Models\Role;
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

        /* Çok portlu PC'de port seçmek şart; listede olmayan port olmaz (Windows'ta COM1–COM10 listededir). */
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, '', '198.51.100.123/24'))->assertSessionHasErrors('seri_port');
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM11', '198.51.100.123/24'))->assertSessionHasErrors('seri_port');

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

    /**
     * Algılama yalnız o an takılı kabloyu görür: COM1–COM10 (Linux'ta
     * /dev/ttyUSB0–9) da elle seçilebilir. Algılanan portun COM adı ikinci kez çıkmaz;
     * "Otomatik" yalnız algılanan port sayısına bakar.
     */
    #[Test]
    public function windowsta_com1_com10_elle_secilebilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc(['ACPI\\PNP0501\\0']);
        $pc->forceFill(['port_bilgileri' => ['ACPI\\PNP0501\\0' => ['com' => 'COM1', 'ad' => 'İletişim Bağlantı Noktası']]])->save();

        $this->assertSame(['ACPI\\PNP0501\\0', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9', 'COM10'], $pc->secilebilirPortlar());
        $this->assertSame(['' => 'Otomatik', 'ACPI\\PNP0501\\0' => 'COM1 · İletişim Bağlantı Noktası'], array_slice(ConsolePage::portSecenekleri($pc), 0, 2, true));

        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM5'))->assertSessionHasNoErrors();
        $this->assertSame('COM5', ConsoleJob::sole()->seri_port);
        $this->actingAs($yonetici)->post(route('console.store'), $this->is($pc, 'COM11', '198.51.100.121/24'))->assertSessionHasErrors('seri_port');

        /* Linux'ta COM yok: düz adlar /dev/ttyUSB0–9. */
        $pc->forceFill(['platform' => 'linux'])->save();
        $this->assertSame(['ACPI\\PNP0501\\0', ...array_map(fn (int $n): string => "/dev/ttyUSB{$n}", range(0, 9))], $pc->fresh()->secilebilirPortlar());
    }

    /**
     * "Portları listele": PC'ye o an takılı portlar okunur, liste tazelenir;
     * takılı olanlar adıyla ve aktif işaretiyle gelir. Linux'ta düz adlar
     * /dev/ttyUSB0–9. PC'ye ulaşılamazsa nedeni söylenir.
     */
    #[Test]
    public function portlari_listele_takili_olanlari_okur(): void
    {
        $teknik = User::factory()->rol('teknik')->create();
        Role::where('slug', 'teknik')->sole()->update(['yetkiler' => ['konsol' => ['view', 'create']]]);
        $teknik = $teknik->fresh();
        $pc = $this->pc([]);
        $ch340 = 'USB\VID_1A86&PID_7523\6&33E0B10C&0&1';
        $cikti = "{$ch340}|COM9|USB-SERIAL CH340\r\n";
        $hata = false;
        Process::fake(function () use (&$cikti, &$hata) {
            return Process::result((string) json_encode($hata
                ? ['ok' => false, 'error' => 'timed out']
                : ['ok' => true, 'status' => 0, 'stdout' => base64_encode($cikti), 'stderr' => '']));
        });

        $yanit = $this->actingAs($teknik)->postJson(route('console.ports'), ['pc' => $pc->uuid])->assertOk()
            ->assertJsonPath('otomatik', true)
            ->assertJsonPath('portlar.0', ['deger' => $ch340, 'etiket' => 'COM9 · USB-SERIAL CH340', 'aktif' => true]);
        /* COM9 algılandı: düz COM9 ikinci kez çıkmaz. */
        $this->assertSame(['COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM10'],
            array_column(array_slice($yanit->json('portlar'), 1), 'deger'));
        $this->assertSame([$ch340], $pc->refresh()->portlar(), 'kayıt da güncellenir');

        /* Linux'ta düz adlar /dev/ttyUSB0–9; by-id yolunun hedefi tekrar listelenmez. */
        $pc->forceFill(['platform' => 'linux'])->save();
        $cikti = "/dev/serial/by-id/usb-1a86_USB_Serial-if00-port0|/dev/ttyUSB0|usb-1a86_USB_Serial-if00-port0\n";
        $this->actingAs($teknik)->postJson(route('console.ports'), ['pc' => $pc->uuid])->assertOk()
            ->assertJsonPath('portlar.0.deger', '/dev/serial/by-id/usb-1a86_USB_Serial-if00-port0')
            ->assertJsonPath('portlar.1.deger', '/dev/ttyUSB1')
            ->assertJsonCount(10, 'portlar');

        /* Ulaşılamayan PC: 422 ve neden. */
        $hata = true;
        $this->actingAs($teknik)->postJson(route('console.ports'), ['pc' => $pc->uuid])->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_starts_with($m, "PC'ye ulaşılamadı"));

        /* İş açamayan listeleyemez: PC'ye komut gönderir. */
        $this->actingAs(User::factory()->create())->postJson(route('console.ports'), ['pc' => $pc->uuid])->assertForbidden();
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
