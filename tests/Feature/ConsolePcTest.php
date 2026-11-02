<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Models\ConsolePc;
use App\ConsoleModule\Remote\SitePc;
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

        $this->actingAs($yonetici)->post(route('console.pc.store'), [
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'kullanici' => 'OPERATÖR',
            'parola' => 'gizli-parola-123', 'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('console.index'));

        $pc = ConsolePc::sole();
        $this->assertSame(5985, $pc->port);
        $this->assertSame('C:\\Console-Agent', $pc->dizin);
        $this->assertStringNotContainsString('gizli-parola-123', (string) ConsolePc::query()->getQuery()->value('parola'));

        $this->actingAs($yonetici)->get(route('console.index'))->assertOk()
            ->assertSee('OPERATÖR@198.51.100.16:5985')
            ->assertDontSee('gizli-parola-123');

        /* Dizin uzak betiğe yazılıyor: tırnak giremez. */
        $this->actingAs($yonetici)->put(route('console.pc.update', $pc), [
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'kullanici' => 'OPERATÖR',
            'cihaz_ip' => '198.51.100.120/24', 'gecit' => '198.51.100.1', 'dizin' => "C:\\x'; Remove-Item C:\\ -Recurse; '",
        ])->assertSessionHasErrors('dizin');

        /* Loader komutu yalnız IPv4 alır; IPv6 geçit 500 yerine alan hatası verir. */
        $this->actingAs($yonetici)->put(route('console.pc.update', $pc), [
            'ad' => 'PC-01', 'platform' => 'windows', 'adres' => '198.51.100.16', 'kullanici' => 'OPERATÖR',
            'cihaz_ip' => '198.51.100.120/24', 'gecit' => '2001:db8::1',
        ])->assertSessionHasErrors('gecit');

        /* Parola boş bırakılırsa değişmez. */
        $this->actingAs($yonetici)->put(route('console.pc.update', $pc), [
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

        $this->actingAs($yonetici)->post(route('console.pc.islem', [$pc, 'install']))->assertRedirect(route('console.index'))
            ->assertSessionHas('pcSonuc', fn (array $s): bool => $s['hata'] === false && str_contains($s['metin'], 'Kuruldu'));

        $ilk = $pc->refresh()->jeton_id;
        $this->assertNotNull($ilk);
        $jeton = ApiToken::findOrFail($ilk);
        $this->assertSame(['198.51.100.16'], $jeton->allowed_ips);

        /* NAT: panelin gördüğü adres de kabul edilir. */
        $pc->update(['gorunen_adres' => '192.0.2.1']);
        $this->actingAs($yonetici)->post(route('console.pc.islem', [$pc, 'install']));
        $this->assertSame(['198.51.100.16', '192.0.2.1'], ApiToken::findOrFail($pc->refresh()->jeton_id)->allowed_ips);
        $ilk = $pc->jeton_id;
        $istekler = [$istekler[0]];
        $this->assertTrue($jeton->permits('konsol', 'ajan'));

        $istek = $istekler[0];
        $this->assertSame('run', $istek['action']);
        $this->assertSame('OPERATÖR', $istek['username']);
        $this->assertSame(5985, $istek['port']);
        /* pywinrm 0.3.0 tek ortam değişkeni taşıyabiliyor: hepsi CONSOLE_ENV'da. */
        $this->assertSame(['CONSOLE_ENV'], array_keys($istek['env']));
        $ortam = json_decode($istek['env']['CONSOLE_ENV'], true);
        $this->assertStringStartsWith('syd_', $ortam['CONSOLE_TOKEN']);
        $baslatici = mb_convert_encoding(base64_decode((string) end($istek['arguments'])), 'UTF-8', 'UTF-16LE');
        $this->assertStringContainsString('& ([scriptblock]::Create($env:CONSOLE_SCRIPT))', $baslatici);
        $this->assertLessThan(1000, strlen((string) end($istek['arguments'])), 'komut satırında yalnız başlatıcı (cmd.exe 8191 sınırı)');
        $this->assertStringNotContainsString($ortam['CONSOLE_TOKEN'], $baslatici.$ortam['CONSOLE_SCRIPT'], 'jeton betiğe yazılmamalı; $env:CONSOLE_TOKEN okunur');
        $betik = $ortam['CONSOLE_SCRIPT'];
        $this->assertStringContainsString(str_repeat('b', 64), $betik, 'sağlama denetlenmeli');
        $this->assertStringContainsString('/api/v1/console/agent/windows', $betik);
        $this->assertStringNotContainsString('NetFirewallRule', $betik, 'ajan dinlemez: güvenlik duvarına dokunulmaz');
        $this->assertStringNotContainsString('New-NetIPAddress', $betik, 'ağ kartlarına dokunulmaz');

        /* Kaldırınca jeton çözülür. */
        $this->actingAs($yonetici)->post(route('console.pc.islem', [$pc, 'uninstall']));
        $kaldir = json_decode($istekler[count($istekler) - 1]['env']['CONSOLE_ENV'], true)['CONSOLE_SCRIPT'];
        $this->assertStringContainsString('Unregister-ScheduledTask', $kaldir);
        $this->assertNull($pc->refresh()->jeton_id, 'kaldırınca jeton çözülür');
        $istekler = [$istekler[0]];

        /* İkinci kurulum yeni jeton üretir, eskisini iptal eder. */
        $this->actingAs($yonetici)->post(route('console.pc.islem', [$pc, 'install']));
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

            /* Sına iki komut: bağlantı ve port okuma. */
            return count($istekler) <= 2
                ? Process::result($this->basarili(count($istekler) === 1 ? 'linux-pc · Debian 12 · root' : '', 'SHA256:abc'))
                : Process::result((string) json_encode(['ok' => true, 'status' => 1, 'stdout' => '', 'stderr' => base64_encode('İndirilen dosyanın sağlaması tutmadı'), 'fingerprint' => 'SHA256:abc']));
        });

        $this->actingAs($yonetici)->post(route('console.pc.islem', [$pc, 'test']))
            ->assertSessionHas('pcSonuc', fn (array $s): bool => str_contains($s['metin'], 'linux-pc'));
        $this->assertSame('SHA256:abc', $pc->refresh()->ssh_parmak_izi);

        $this->actingAs($yonetici)->post(route('console.pc.islem', [$pc, 'install']))
            ->assertSessionHas('pcSonuc', fn (array $s): bool => $s['hata'] === true && str_contains($s['metin'], 'sağlaması tutmadı'));

        $this->assertSame('SHA256:abc', $istekler[2]['fingerprint'], 'ikinci bağlantı kayıtlı izle doğrulanmalı');
        $this->assertSame(2222, $istekler[2]['port']);
        $this->assertStringContainsString("-H @<(printf 'Authorization: Bearer %s' \"\$JETON\")", $istekler[2]['script'], 'jeton curl argümanına girmemeli');
        $this->assertStringContainsString(str_repeat('a', 64).'  $d/console-agent.new', $istekler[2]['script']);
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
        config(['console.panel_certificate' => $this->dizin.'/panel.crt']);

        $yonetici = User::factory()->admin()->create();
        $istekler = [];
        Process::fake(function (PendingProcess $p) use (&$istekler) {
            $istekler[] = json_decode((string) $p->input, true);

            return Process::result($this->basarili('Kuruldu'));
        });

        $this->actingAs($yonetici)->post(route('console.pc.islem', [$this->pc('windows'), 'install']));
        $this->actingAs($yonetici)->post(route('console.pc.islem', [$this->pc('linux'), 'install']));
        @unlink($this->dizin.'/panel.crt');

        $der = hash('sha256', (string) base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem)));
        $windowsOrtam = json_decode($istekler[0]['env']['CONSOLE_ENV'], true);
        $windows = $windowsOrtam['CONSOLE_SCRIPT'];
        $this->assertStringContainsString("[KonsolSabit]::Beklenen = '{$der}'", $windows);
        $this->assertStringContainsString('ServerCertificateValidationCallback = $null', $windows);
        $this->assertStringNotContainsString('PRIVATE KEY', $windows);
        $this->assertStringContainsString('"ca_file": "panel.pem"', $windowsOrtam['CONSOLE_CONFIG']);

        $linux = $istekler[1]['script'];
        $this->assertStringContainsString("<<'CONSOLE_PANEL_PEM'\n-----BEGIN CERTIFICATE-----", $linux);
        $this->assertStringContainsString('--cacert "$d/panel.pem"', $linux);
        $this->assertStringNotContainsString('PRIVATE KEY', $linux);
    }

    /**
     * Önde bir vekil (Traefik) dosyadakinden BAŞKA bir sertifika sunuyorsa PC
     * sunulana sabitlenmeli: dosyadakine sabitlenen PC indirmeyi "güven
     * ilişkisi kurulamadı" diye reddediyordu.
     */
    #[Test]
    public function pc_adreste_sunulan_sertifikaya_sabitlenir(): void
    {
        if (! is_executable('/usr/bin/openssl')) {
            $this->markTestSkipped('openssl yok: sınama TLS sunucusu açılamıyor');
        }

        $sertifika = function (string $ad): array {
            $anahtar = openssl_pkey_new(['private_key_bits' => 2048]);
            openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => $ad], $anahtar), null, $anahtar, 30), $pem);
            openssl_pkey_export($anahtar, $ozel);

            return [$pem, $ozel];
        };
        [$dosyadaki] = $sertifika('dosya.ornek.test');
        [$sunulan, $sunulanAnahtar] = $sertifika('vekil.ornek.test');
        file_put_contents($this->dizin.'/panel.crt', $dosyadaki);
        file_put_contents($this->dizin.'/vekil.crt', $sunulan);
        file_put_contents($this->dizin.'/vekil.key', $sunulanAnahtar);

        $port = 20000 + random_int(0, 9999);
        $sunucu = proc_open(['/usr/bin/openssl', 's_server', '-quiet', '-accept', (string) $port,
            '-cert', $this->dizin.'/vekil.crt', '-key', $this->dizin.'/vekil.key'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $borular);

        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100_000);
            }
            config([
                'app.url' => "https://127.0.0.1:{$port}",
                'console.panel_certificate' => $this->dizin.'/panel.crt',
                'console.panel_certificate_probe' => true,
            ]);

            $pem = app(SitePc::class)->kendindenImzaliSertifika();

            $this->assertSame(openssl_x509_fingerprint($sunulan, 'sha256'), openssl_x509_fingerprint((string) $pem, 'sha256'));
        } finally {
            proc_terminate($sunucu);
            proc_close($sunucu);
            foreach (['panel.crt', 'vekil.crt', 'vekil.key'] as $f) {
                @unlink($this->dizin.'/'.$f);
            }
        }
    }

    #[Test]
    public function durum_bolumleri_ayristirilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $pc = $this->pc('windows');

        $cagri = 0;
        Process::fake(function () use (&$cagri) {
            return Process::result($this->basarili(++$cagri === 1
                ? "@@GOREV\nRunning\n@@SURUM\nconsole-agent 4407025754bc\n@@KAYIT\nconsole-agent başladı\n"
                : "FTDIBUS\\VID_0403+PID_6001+A50285BIA\\0000|COM3|USB Serial Port\n"));
        });

        $this->actingAs($yonetici)->post(route('console.pc.islem', [$pc, 'status']))
            ->assertSessionHas('pcSonuc', fn (array $s): bool => $s['durum']['gorev'] === 'Running'
                && $s['durum']['portlar'] === ['COM3 · USB Serial Port'] && $s['durum']['kayit'] === ['console-agent başladı']);
    }

    #[Test]
    public function yetkisiz_rol_pc_yonetemez(): void
    {
        $pc = $this->pc('windows');
        Process::fake();

        $this->actingAs(User::factory()->rol('network')->create())
            ->post(route('console.pc.islem', [$pc, 'install']))->assertRedirect(route('forbidden'));
        Process::assertNothingRan();
    }
}
