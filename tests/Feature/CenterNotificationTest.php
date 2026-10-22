<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MERKEZ ROL/DURUM DEĞİŞİNCE ANINDA HABER VERİR.
 *
 * Panel bunu zamanlanmış eşitleme turunu beklemeden öğrenir; erişimi
 * kaldırılan biri tur gelene kadar çalışmaya devam etmemeli.
 */
class CenterNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const SIR = 'bildirim-sirri';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            'identity.configured' => true,
            'identity.url' => 'https://merkez.test',
            'identity.client_id' => 'istemci',
            'identity.client_secret' => 'sir',
            'identity.notify_secret' => self::SIR,
        ]);

        Cache::flush();
    }

    private function bagli(string $konu = 'kimlik-1'): User
    {
        $kullanici = User::factory()->rol('muhasebe')->create();

        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => AccountMatcher::SAGLAYICI,
            'subject' => $konu,
            'email' => $kullanici->email,
        ]);

        return $kullanici;
    }

    private function bildir(string $konu, ?string $imza = null): TestResponse
    {
        return $this->postJson(route('api.identity.refresh'), ['subject' => $konu], [
            'X-Identity-Signature' => $imza ?? hash_hmac('sha256', $konu, self::SIR),
        ]);
    }

    public function test_imzali_bildirim_merkeze_sorar_ve_uygular(): void
    {
        $kullanici = $this->bagli();

        Http::fake(['merkez.test/api/identity-status' => Http::response([
            'identities' => ['kimlik-1' => ['status' => 'askida', 'can_enter' => false, 'role' => 'muhasebe']],
        ])]);

        $this->bildir('kimlik-1')->assertOk()->assertJson(['result' => 'received']);

        $this->assertFalse($kullanici->fresh()->aktif);
    }

    public function test_imzasiz_bildirim_hicbir_sey_yapmaz(): void
    {
        $kullanici = $this->bagli();

        Http::fake();

        /*
         * Yanıt yine "alındı": bu uçtan "kimlik burada var mı" ya da "imza
         * tuttu mu" öğrenilememeli.
         */
        $this->bildir('kimlik-1', 'uydurma-imza')->assertOk();

        $this->assertTrue($kullanici->fresh()->aktif);
        Http::assertNothingSent();
    }

    public function test_gelen_govdeye_guvenilmez(): void
    {
        /*
         * Bildirim yalnızca HANGİ kimliğin değiştiğini söyler. Gövdeye rol
         * yazılsa bile okunmaz; panel doğruyu merkezden kendi sorar. İmza bir
         * gün sızsa bile kimsenin rolü yükseltilemez.
         */
        $kullanici = $this->bagli();

        Http::fake(['merkez.test/api/identity-status' => Http::response([
            'identities' => ['kimlik-1' => ['status' => 'etkin', 'can_enter' => true, 'role' => 'muhasebe']],
        ])]);

        $this->postJson(route('api.identity.refresh'), [
            'subject' => 'kimlik-1',
            'role' => 'admin',
        ], ['X-Identity-Signature' => hash_hmac('sha256', 'kimlik-1', self::SIR)])->assertOk();

        $this->assertSame('muhasebe', $kullanici->fresh()->rol?->slug);
        $this->assertFalse($kullanici->fresh()->isAdmin());
    }

    public function test_burada_karsiligi_olmayan_kimlik_icin_merkeze_gidilmez(): void
    {
        /* Bildirim bütün panellere gidiyor; herkesin her panelde hesabı yok. */
        Http::fake();

        $this->bildir('tanimadigim-kimlik')->assertOk();

        Http::assertNothingSent();
    }

    public function test_sir_tanimsizsa_bildirim_kabul_edilmez(): void
    {
        config(['identity.notify_secret' => null]);

        $kullanici = $this->bagli();
        Http::fake();

        $this->postJson(route('api.identity.refresh'), ['subject' => 'kimlik-1'], [
            'X-Identity-Signature' => hash_hmac('sha256', 'kimlik-1', ''),
        ])->assertOk();

        $this->assertTrue($kullanici->fresh()->aktif);
        Http::assertNothingSent();
    }
}
