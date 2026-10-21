<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Kimlik\HesapEslestirici;
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
class YerelRolKorunurTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kimlik.acik' => true,
            'kimlik.yapilandirildi' => true,
            'kimlik.adres' => 'https://merkez.test',
            'kimlik.istemci' => 'istemci',
            'kimlik.sir' => 'sir',
        ]);

        Cache::flush();
    }

    private function merkezMuhasebeDiyor(User $kullanici): void
    {
        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => HesapEslestirici::SAGLAYICI,
            'subject' => 'kimlik-1',
            'email' => $kullanici->email,
        ]);

        Http::fake(['merkez.test/api/kimlik-durumu' => Http::response([
            'kimlikler' => ['kimlik-1' => ['durum' => 'etkin', 'girebilir' => true, 'rol' => 'muhasebe']],
        ])]);
    }

    public function test_ekrandan_verilen_rol_esitlemede_korunur(): void
    {
        $yonetici = User::factory()->admin()->create();
        $hedef = User::factory()->rol('muhasebe')->create();

        $this->merkezMuhasebeDiyor($hedef);

        $this->actingAs($yonetici)->post(route('admin.kullanicilar.update', $hedef), [
            'ad' => $hedef->name,
            'eposta' => $hedef->email,
            'rol' => 'admin',
            'aktif' => '1',
        ])->assertRedirect();

        $this->assertSame('admin', $hedef->fresh()->rol?->slug);
        $this->assertTrue($hedef->fresh()->rol_yerel);

        $this->artisan('kimlik:esitle')->assertSuccessful();

        /* Merkez "muhasebe" diyor ama panelde verilen rol duruyor. */
        $this->assertSame('admin', $hedef->fresh()->rol?->slug);
        $this->assertTrue($hedef->fresh()->isAdmin());
    }

    public function test_yerel_isaret_yoksa_merkez_yazar(): void
    {
        $kullanici = User::factory()->rol('teknik')->create(['rol_yerel' => false]);

        $this->merkezMuhasebeDiyor($kullanici);

        $this->artisan('kimlik:esitle')->assertSuccessful();

        $this->assertSame('muhasebe', $kullanici->fresh()->rol?->slug);
    }

    public function test_merkezden_acilan_hesap_yerel_isaretsiz_dogar(): void
    {
        /* Varsayılan davranış "merkez ne diyorsa o" olmayı sürdürmeli. */
        Http::fake([
            'merkez.test/oauth/token' => Http::response(['access_token' => 'jeton']),
            'merkez.test/oauth/kullanici' => Http::response([
                'sub' => 'kimlik-9',
                'name' => 'Yeni Muhasebeci',
                'email' => 'yeni@ornek.test',
                'email_verified' => true,
                'rol' => 'muhasebe',
                'hesap_acilabilir' => true,
            ]),
        ]);

        $this->withSession(['kimlik_durum' => 'durum', 'kimlik_dogrulayici' => 'dogrulayici'])
            ->get('/giris/merkez/geri?state=durum&code=kod');

        $yeni = User::where('email', 'yeni@ornek.test')->first();

        $this->assertNotNull($yeni);
        $this->assertFalse($yeni->rol_yerel);
    }
}
