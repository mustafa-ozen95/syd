<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ERİŞİM KARARI MERKEZİNDİR.
 *
 * Hangi rolün bu panele girebileceğine ve hesabın kendiliğinden açılıp
 * açılmayacağına merkez karar verir; panel yalnızca uygular. Aynı karar iki
 * yerde durursa biri değişince öbürü sessizce eski kalır — bu depolarda en
 * çok tekrar eden hata sınıfı budur.
 */
class CenterDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            'identity.configured' => true,
            'identity.url' => 'https://merkez.test',
            'identity.client_id' => 'istemci',
            'identity.client_secret' => 'sir',
        ]);

        Cache::flush();
    }

    private function donus(): TestResponse
    {
        return $this->withSession(['kimlik_durum' => 'durum', 'kimlik_dogrulayici' => 'dogrulayici'])
            ->get('/login/center/callback?state=durum&code=kod');
    }

    public function test_merkez_yetkisiz_derse_kapi_kapanir(): void
    {
        User::factory()->create(['email' => 'kisi@ornek.test']);

        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton']),
            'merkez.test/oauth/userinfo' => Http::response([
                'code' => 'role_forbidden',
                'error' => 'Bu uygulamaya erişim yetkiniz yok.',
            ], 403),
        ]);

        $this->donus()->assertRedirect(route('login'));
        $this->assertGuest();

        /* Red kayda geçer: kimin nereye girmeye çalıştığı sorulacak. */
        $this->assertNotNull(ActivityLog::where('tur', 'merkez-giris-reddedildi')->first());
    }

    public function test_merkez_izin_verirse_hesap_acilir(): void
    {
        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton']),
            'merkez.test/oauth/userinfo' => Http::response([
                'sub' => 'kimlik-1',
                'name' => 'Muhasebeci',
                'email' => 'muhasebe@ornek.test',
                'email_verified' => true,
                'role' => 'muhasebe',
                'can_create_account' => true,
            ]),
        ]);

        $this->donus()->assertRedirect(route('backup.index'));

        $kullanici = User::where('email', 'muhasebe@ornek.test')->first();

        $this->assertNotNull($kullanici);
        $this->assertSame('Muhasebeci', $kullanici->name, 'ad merkezden gelir');
        $this->assertSame('muhasebe', $kullanici->rol?->slug, 'rol merkezden gelir, yöneticilik devrolmaz');
        $this->assertTrue($kullanici->aktif);
        $this->assertAuthenticatedAs($kullanici);
        $this->assertDatabaseHas('user_identities', ['subject' => 'kimlik-1']);
        $this->assertNotNull(ActivityLog::where('tur', 'merkez-hesap-acildi')->first());
    }

    public function test_merkez_izin_vermezse_hesap_acilmaz(): void
    {
        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton']),
            'merkez.test/oauth/userinfo' => Http::response([
                'sub' => 'kimlik-1',
                'name' => 'Kişi',
                'email' => 'kisi@ornek.test',
                'email_verified' => true,
                'role' => 'muhasebe',
                'can_create_account' => false,
            ]),
        ]);

        $this->donus()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'kisi@ornek.test']);
    }

    public function test_dogrulanmamis_adrese_hesap_acilmaz(): void
    {
        /* Merkez izin verse bile: doğrulanmamış adresle hesap açmak,
           meslektaşının adresiyle kayıt olan birine burada hesap vermektir. */
        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton']),
            'merkez.test/oauth/userinfo' => Http::response([
                'sub' => 'kimlik-1',
                'name' => 'Kişi',
                'email' => 'kisi@ornek.test',
                'email_verified' => false,
                'role' => 'muhasebe',
                'can_create_account' => true,
            ]),
        ]);

        $this->donus()->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'kisi@ornek.test']);
    }
}
