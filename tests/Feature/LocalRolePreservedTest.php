<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PANELDE ELLE VERİLEN ROL MERKEZDEN EZİLMEZ.
 *
 * Bir kişi yalnızca bu panelde farklı bir rolde olabilir: merkezde başka
 * bir rolde olan biri bu panelin yöneticisi olabilir. İşaret olmasaydı yönetici rolü
 * ekrandan değiştirir, eşitleme turu onu sessizce geri alırdı ve kimse ne
 * olduğunu anlamazdı.
 */
class LocalRolePreservedTest extends TestCase
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

    private function merkezMuhasebeDiyor(User $kullanici): void
    {
        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => AccountMatcher::SAGLAYICI,
            'subject' => 'kimlik-1',
            'email' => $kullanici->email,
        ]);

        Http::fake(['merkez.test/api/identity-status' => Http::response([
            'identities' => ['kimlik-1' => ['status' => 'etkin', 'can_enter' => true, 'role' => 'muhasebe']],
        ])]);
    }

    public function test_ekrandan_verilen_rol_esitlemede_korunur(): void
    {
        $yonetici = User::factory()->admin()->create();
        $hedef = User::factory()->rol('muhasebe')->create();

        $this->merkezMuhasebeDiyor($hedef);

        $this->actingAs($yonetici)->post(route('admin.users.update', $hedef), [
            'ad' => $hedef->name,
            'eposta' => $hedef->email,
            'rol' => 'admin',
            'aktif' => '1',
        ])->assertRedirect();

        $this->assertSame('admin', $hedef->fresh()->rol?->slug);
        $this->assertTrue($hedef->fresh()->rol_yerel);

        $this->artisan('identity:sync')->assertSuccessful();

        /* Merkez "muhasebe" diyor ama panelde verilen rol duruyor. */
        $this->assertSame('admin', $hedef->fresh()->rol?->slug);
        $this->assertTrue($hedef->fresh()->isAdmin());
    }

    public function test_yerel_isaret_yoksa_merkez_yazar(): void
    {
        $kullanici = User::factory()->rol('teknik')->create(['rol_yerel' => false]);

        $this->merkezMuhasebeDiyor($kullanici);

        $this->artisan('identity:sync')->assertSuccessful();

        $this->assertSame('muhasebe', $kullanici->fresh()->rol?->slug);
    }

    public function test_merkezden_acilan_hesap_yerel_isaretsiz_dogar(): void
    {
        /* Varsayılan davranış "merkez ne diyorsa o" olmayı sürdürmeli. */
        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton']),
            'merkez.test/oauth/userinfo' => Http::response([
                'sub' => 'kimlik-9',
                'name' => 'Yeni Muhasebeci',
                'email' => 'yeni@ornek.test',
                'email_verified' => true,
                'role' => 'muhasebe',
                'can_create_account' => true,
            ]),
        ]);

        $this->withSession(['kimlik_durum' => 'durum', 'kimlik_dogrulayici' => 'dogrulayici'])
            ->get('/login/center/callback?state=durum&code=kod');

        $yeni = User::where('email', 'yeni@ornek.test')->first();

        $this->assertNotNull($yeni);
        $this->assertFalse($yeni->rol_yerel);
    }
}
