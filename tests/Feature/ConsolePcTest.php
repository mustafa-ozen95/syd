<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Models\ConsolePc;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Saha PC'leri: panel ajanı Windows'a WinRM,
 * Linux'a SSH ile kurar. Uzak makine yerine yardımcı betik taklit edilir;
 * sınanan şey panelin ne gönderdiği ve sırların nereden gittiği.
 */
final class ConsolePcTest extends TestCase
{
    use RefreshDatabase;

    private string $dizin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dizin = sys_get_temp_dir().'/konsol-ajan-'.bin2hex(random_bytes(4));
        mkdir($this->dizin);
        file_put_contents($this->dizin.'/SHA256SUMS', str_repeat('a', 64)."  console-agent-linux-amd64\n".str_repeat('b', 64)."  console-agent-windows-amd64.exe\n");
        config(['konsol.ajan_dizini' => $this->dizin]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dizin.'/SHA256SUMS');
        @rmdir($this->dizin);

        parent::tearDown();
    }

    private function pc(string $platform): ConsolePc
    {
        return ConsolePc::create([
            'ad' => $platform === 'windows' ? 'PC-01' : 'Linux PC',
            'platform' => $platform,
            'adres' => $platform === 'windows' ? '198.51.100.16' : '198.51.100.15',
            'port' => $platform === 'windows' ? 5985 : 2222,
            'kullanici' => $platform === 'windows' ? 'OPERATÖR' : 'root',
            'parola' => 'gizli-parola-123',
            'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1',
            'dizin' => ConsolePc::PLATFORMLAR[$platform]['dizin'],
        ]);
    }

    private function basarili(string $cikti, ?string $iz = null): string
    {
        return (string) json_encode(['ok' => true, 'status' => 0, 'stdout' => base64_encode($cikti), 'stderr' => '', 'fingerprint' => $iz]);
    }

    #[Test]
    public function pc_eklenir_parola_sifreli_saklanir_ve_geri_gosterilmez(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->post(route('konsol.pc.store'), [
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'kullanici' => 'OPERATÖR',
            'parola' => 'gizli-parola-123', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('konsol.index'));

        $pc = ConsolePc::sole();
        $this->assertSame(5985, $pc->port);
        $this->assertSame('C:\\Konsol-Ajan', $pc->dizin);
        $this->assertStringNotContainsString('gizli-parola-123', (string) ConsolePc::query()->getQuery()->value('parola'));

        $this->actingAs($yonetici)->get(route('konsol.index'))->assertOk()
            ->assertSee('OPERATÖR@198.51.100.16:5985')
            ->assertDontSee('gizli-parola-123');

        /* Dizin uzak betiğe yazılıyor: tırnak giremez. */
        $this->actingAs($yonetici)->put(route('konsol.pc.update', $pc), [
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'kullanici' => 'OPERATÖR',
            'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => "C:\\x'; Remove-Item C:\\ -Recurse; '",
        ])->assertSessionHasErrors('dizin');

        /* Parola boş bırakılırsa değişmez. */
        $this->actingAs($yonetici)->put(route('konsol.pc.update', $pc), [
            'ad' => 'PC-02', 'platform' => 'windows', 'adres' => '198.51.100.16', 'kullanici' => 'OPERATÖR',
            'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('gizli-parola-123', $pc->refresh()->parola);
    }

    #[Test]
    public function windows_kurulumu_jetonu_ortamdan_gonderir_eskisini_iptal_eder(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc('windows');
        $istekler = [];

        Process::fake(function (PendingProcess $p) use (&$istekler) {
            $istekler[] = json_decode((string) $p->input, true);

            return Process::result($this->basarili('Kuruldu: abc · görev Running'));
        });

        $this->actingAs($yonetici)->post(route('konsol.pc.islem', [$pc, 'kur']))->assertRedirect(route('konsol.index'))
            ->assertSessionHas('pcSonuc', fn (array $s): bool => $s['hata'] === false && str_contains($s['metin'], 'Kuruldu'));

        $ilk = $pc->refresh()->jeton_id;
        $this->assertNotNull($ilk);
        $jeton = ApiToken::findOrFail($ilk);
        $this->assertSame(['198.51.100.16'], $jeton->allowed_ips);

        /* NAT: panelin gördüğü adres de kabul edilir. */
        $pc->update(['gorunen_adres' => '192.0.2.1']);
        $this->actingAs($yonetici)->post(route('konsol.pc.islem', [$pc, 'kur']));
        $this->assertSame(['198.51.100.16', '192.0.2.1'], ApiToken::findOrFail($pc->refresh()->jeton_id)->allowed_ips);
        $ilk = $pc->jeton_id;
        $istekler = [$istekler[0]];
        $this->assertTrue($jeton->permits('konsol', 'ajan'));

        $istek = $istekler[0];
        $this->assertSame('run', $istek['action']);
        $this->assertSame('OPERATÖR', $istek['username']);
        $this->assertSame(5985, $istek['port']);
        /* pywinrm 0.3.0 tek ortam değişkeni taşıyabiliyor: hepsi KONSOL_ORTAM'da. */
        $this->assertSame(['KONSOL_ORTAM'], array_keys($istek['env']));
        $ortam = json_decode($istek['env']['KONSOL_ORTAM'], true);
        $this->assertStringStartsWith('syd_', $ortam['KONSOL_JETON']);
        $baslatici = mb_convert_encoding(base64_decode((string) end($istek['arguments'])), 'UTF-8', 'UTF-16LE');
        $this->assertStringContainsString('& ([scriptblock]::Create($env:KONSOL_BETIK))', $baslatici);
        $this->assertLessThan(1000, strlen((string) end($istek['arguments'])), 'komut satırında yalnız başlatıcı (cmd.exe 8191 sınırı)');
        $this->assertStringNotContainsString($ortam['KONSOL_JETON'], $baslatici.$ortam['KONSOL_BETIK'], 'jeton betiğe yazılmamalı; $env:KONSOL_JETON okunur');
        $betik = $ortam['KONSOL_BETIK'];
        $this->assertStringContainsString(str_repeat('b', 64), $betik, 'sağlama denetlenmeli');
        $this->assertStringContainsString('/api/v1/konsol/ajan/windows', $betik);
        $this->assertStringNotContainsString('NetFirewallRule', $betik, 'ajan dinlemez: güvenlik duvarına dokunulmaz');
        $this->assertStringNotContainsString('New-NetIPAddress', $betik, 'ağ kartlarına dokunulmaz');

        /* Kaldırınca jeton çözülür. */
        $this->actingAs($yonetici)->post(route('konsol.pc.islem', [$pc, 'kaldir']));
        $kaldir = json_decode($istekler[count($istekler) - 1]['env']['KONSOL_ORTAM'], true)['KONSOL_BETIK'];
        $this->assertStringContainsString('Unregister-ScheduledTask', $kaldir);
        $this->assertNull($pc->refresh()->jeton_id, 'kaldırınca jeton çözülür');
        $istekler = [$istekler[0]];

        /* İkinci kurulum yeni jeton üretir, eskisini iptal eder. */
        $this->actingAs($yonetici)->post(route('konsol.pc.islem', [$pc, 'kur']));
        $this->assertNotSame($ilk, $pc->refresh()->jeton_id);
        $this->assertNotNull(ApiToken::findOrFail($ilk)->revoked_at);
    }

    #[Test]
    public function linux_ilk_baglantida_anahtar_izini_saklar_kurulum_basarisizsa_jeton_iptal(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc('linux');
        $istekler = [];

        Process::fake(function (PendingProcess $p) use (&$istekler) {
            $istekler[] = json_decode((string) $p->input, true);

            return count($istekler) === 1
                ? Process::result($this->basarili('linux-pc · Debian 12 · root', 'SHA256:abc'))
                : Process::result((string) json_encode(['ok' => true, 'status' => 1, 'stdout' => '', 'stderr' => base64_encode('İndirilen dosyanın sağlaması tutmadı'), 'fingerprint' => 'SHA256:abc']));
        });

        $this->actingAs($yonetici)->post(route('konsol.pc.islem', [$pc, 'sina']))
            ->assertSessionHas('pcSonuc', fn (array $s): bool => str_contains($s['metin'], 'linux-pc'));
        $this->assertSame('SHA256:abc', $pc->refresh()->ssh_parmak_izi);

        $this->actingAs($yonetici)->post(route('konsol.pc.islem', [$pc, 'kur']))
            ->assertSessionHas('pcSonuc', fn (array $s): bool => $s['hata'] === true && str_contains($s['metin'], 'sağlaması tutmadı'));

        $this->assertSame('SHA256:abc', $istekler[1]['fingerprint'], 'ikinci bağlantı kayıtlı izle doğrulanmalı');
        $this->assertSame(2222, $istekler[1]['port']);
        $this->assertStringContainsString("-H @<(printf 'Authorization: Bearer %s' \"\$JETON\")", $istekler[1]['script'], 'jeton curl argümanına girmemeli');
        $this->assertStringContainsString(str_repeat('a', 64).'  $d/konsol-ajan.yeni', $istekler[1]['script']);
        $this->assertNull($pc->refresh()->jeton_id);
        $this->assertSame(1, ApiToken::count());
        $this->assertNotNull(ApiToken::sole()->revoked_at, 'başarısız kurulumun jetonu iptal edilmeli');
    }

    #[Test]
    public function kendinden_imzali_panel_sertifikasi_pc_ye_sabitlenir(): void
    {
        $anahtar = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'syd.ornek.test'], $anahtar);
        openssl_x509_export(openssl_csr_sign($csr, null, $anahtar, 30), $pem);
        openssl_pkey_export($anahtar, $ozel);
        /* Dosyada özel anahtar da olsa PC'ye yalnız sertifika gitmeli. */
        file_put_contents($this->dizin.'/panel.crt', $pem.$ozel);
        config(['konsol.panel_sertifikasi' => $this->dizin.'/panel.crt']);

        $yonetici = User::factory()->admin()->create();
        $istekler = [];
        Process::fake(function (PendingProcess $p) use (&$istekler) {
            $istekler[] = json_decode((string) $p->input, true);

            return Process::result($this->basarili('Kuruldu'));
        });

        $this->actingAs($yonetici)->post(route('konsol.pc.islem', [$this->pc('windows'), 'kur']));
        $this->actingAs($yonetici)->post(route('konsol.pc.islem', [$this->pc('linux'), 'kur']));
        @unlink($this->dizin.'/panel.crt');

        $der = hash('sha256', (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem)));
        $windowsOrtam = json_decode($istekler[0]['env']['KONSOL_ORTAM'], true);
        $windows = $windowsOrtam['KONSOL_BETIK'];
        $this->assertStringContainsString("[KonsolSabit]::Beklenen = '{$der}'", $windows);
        $this->assertStringContainsString('ServerCertificateValidationCallback = $null', $windows);
        $this->assertStringNotContainsString('PRIVATE KEY', $windows);
        $this->assertStringContainsString('"ca_dosyasi": "panel.pem"', $windowsOrtam['KONSOL_AYAR']);

        $linux = $istekler[1]['script'];
        $this->assertStringContainsString("<<'KONSOL_PANEL_PEM'\n-----BEGIN CERTIFICATE-----", $linux);
        $this->assertStringContainsString('--cacert "$d/panel.pem"', $linux);
        $this->assertStringNotContainsString('PRIVATE KEY', $linux);
    }

    #[Test]
    public function durum_bolumleri_ayristirilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc('windows');

        Process::fake(fn () => Process::result($this->basarili(
            "@@GOREV\nRunning\n@@SURUM\nkonsol-ajan 4407025754bc\n@@PORTLAR\nUSB Serial Port (COM3)\n"
            ."@@KAYIT\nkonsol-ajan başladı\n",
        )));

        $this->actingAs($yonetici)->post(route('konsol.pc.islem', [$pc, 'durum']))
            ->assertSessionHas('pcSonuc', fn (array $s): bool => $s['durum']['gorev'] === 'Running'
                && $s['durum']['portlar'] === ['USB Serial Port (COM3)'] && $s['durum']['kayit'] === ['konsol-ajan başladı']);
    }

    #[Test]
    public function yetkisiz_rol_pc_yonetemez(): void
    {
        $pc = $this->pc('windows');
        Process::fake();

        $this->actingAs(User::factory()->rol('network')->create())
            ->post(route('konsol.pc.islem', [$pc, 'kur']))->assertRedirect(route('yetkisiz'));
        Process::assertNothingRan();
    }
}
