<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Kimlik\HesapEslestirici;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ROL BİR KAYITTIR ve merkezdekiyle aynı slug'ı taşır.
 *
 * Rol iki değerli bir dize (admin/user) olsaydı merkezde muhasebe olan biri
 * burada "user" görünür ve "kim muhasebeci" sorusu buradan yanıtlanamazdı.
 */
class RolMerkezdenGelirTest extends TestCase
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

    public function test_yerlesik_roller_merkezdekiyle_ayni(): void
    {
        $this->assertSame(
            ['admin', 'muhasebe', 'teknik', 'network'],
            Rol::sirali()->pluck('slug')->all(),
        );
    }

    public function test_esitleme_rolu_merkezden_yazar(): void
    {
        $kullanici = User::factory()->create(['rol_id' => null]);

        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => HesapEslestirici::SAGLAYICI,
            'subject' => 'kimlik-1',
            'email' => $kullanici->email,
        ]);

        Http::fake(['merkez.test/api/kimlik-durumu' => Http::response([
            'kimlikler' => ['kimlik-1' => ['durum' => 'etkin', 'girebilir' => true, 'rol' => 'muhasebe']],
        ])]);

        $this->artisan('kimlik:esitle')->assertSuccessful();

        $this->assertSame('muhasebe', $kullanici->fresh()->rol?->slug);
    }

    public function test_yoneticilik_devrolmaz(): void
    {
        /*
         * Merkezde rol dağıtabilen biri, buradaki hiçbir yöneticinin haberi
         * olmadan kendini burada da yönetici yapabilirdi.
         */
        $kullanici = User::factory()->rol('muhasebe')->create();

        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => HesapEslestirici::SAGLAYICI,
            'subject' => 'kimlik-1',
            'email' => $kullanici->email,
        ]);

        Http::fake(['merkez.test/api/kimlik-durumu' => Http::response([
            'kimlikler' => ['kimlik-1' => ['durum' => 'etkin', 'girebilir' => true, 'rol' => 'admin']],
        ])]);

        $this->artisan('kimlik:esitle')->assertSuccessful();

        $this->assertSame('muhasebe', $kullanici->fresh()->rol?->slug);
        $this->assertFalse($kullanici->fresh()->isAdmin());
    }

    public function test_taninmayan_role_dokunulmaz(): void
    {
        $kullanici = User::factory()->rol('muhasebe')->create();

        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => HesapEslestirici::SAGLAYICI,
            'subject' => 'kimlik-1',
            'email' => $kullanici->email,
        ]);

        Http::fake(['merkez.test/api/kimlik-durumu' => Http::response([
            'kimlikler' => ['kimlik-1' => ['durum' => 'etkin', 'girebilir' => true, 'rol' => 'bilinmeyen']],
        ])]);

        $this->artisan('kimlik:esitle')->assertSuccessful();

        $this->assertSame('muhasebe', $kullanici->fresh()->rol?->slug);
    }

    public function test_rolsuz_kullanici_yonetici_degildir(): void
    {
        $this->assertFalse(User::factory()->create(['rol_id' => null])->isAdmin());
    }

    public function test_ekranda_rol_etiketi_gorunur(): void
    {
        User::factory()->rol('muhasebe')->create(['name' => 'Muhasebeci Kişi']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.kullanicilar.index'))
            ->assertOk()
            ->assertSee('Muhasebe');
    }
}
