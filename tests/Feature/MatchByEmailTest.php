<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MERKEZLE GİRİŞİN ANAHTARI E-POSTADIR.
 *
 * Kullanıcı ekranında e-posta alanı olmazsa hiçbir hesabın adresi olmaz ve
 * merkezden gelen herkes "eşleşen hesap yok" duvarına çarpar — yöneticinin
 * kendisi dahil. Bu testler alanın kaydedildiğini ve eşleşmenin onunla
 * yapıldığını sınar.
 */
class MatchByEmailTest extends TestCase
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

    public function test_epostasi_olan_hesap_merkezle_girebilir(): void
    {
        $kullanici = User::factory()->admin()->create(['email' => 'admin@ornek.test']);

        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton']),
            'merkez.test/oauth/userinfo' => Http::response([
                'sub' => 'kimlik-1',
                'name' => 'Yönetici',
                'email' => 'admin@ornek.test',
                'email_verified' => true,
                'role' => 'admin',
            ]),
        ]);

        $this->withSession(['kimlik_durum' => 'durum', 'kimlik_dogrulayici' => 'dogrulayici'])
            ->get('/login/center/callback?state=durum&code=kod')
            ->assertRedirect(route('backup.index'));

        $this->assertAuthenticatedAs($kullanici);
        $this->assertDatabaseHas('user_identities', ['subject' => 'kimlik-1', 'user_id' => $kullanici->id]);
    }

    public function test_baska_adresli_hesap_eslesmez(): void
    {
        /*
         * Eşleşme adresin KENDİSİNDEN geçer: merkezdeki adresin panelde
         * karşılığı yoksa hesap bulunmaz. (Hesabın kendiliğinden açılması
         * ayrı bir karar ve onu merkez veriyor.)
         */
        User::factory()->admin()->create(['email' => 'baska@ornek.test']);

        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton']),
            'merkez.test/oauth/userinfo' => Http::response([
                'sub' => 'kimlik-1',
                'name' => 'Yönetici',
                'email' => 'admin@ornek.test',
                'email_verified' => true,
                'role' => 'admin',
                'can_create_account' => false,
            ]),
        ]);

        $this->withSession(['kimlik_durum' => 'durum', 'kimlik_dogrulayici' => 'dogrulayici'])
            ->get('/login/center/callback?state=durum&code=kod')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
