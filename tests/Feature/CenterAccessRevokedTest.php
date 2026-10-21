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
 * Merkezde erişimi kalkan kişi BURADA DA kalmaz.
 *
 * Girişte bakmak yetmiyordu: değişiklik, kişi hiç giriş yapmadan da olur ve
 * o sırada oturumu açıksa panelde gezinmeye devam ediyordu.
 */
class CenterAccessRevokedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            'identity.yapilandirildi' => true,
            'identity.adres' => 'https://merkez.test',
            'identity.istemci' => 'istemci',
            'identity.sir' => 'sir',
        ]);

        Cache::flush();
    }

    private function merkeziBagliKullanici(string $konu = 'kimlik-1'): User
    {
        $kullanici = User::factory()->yedekci()->create();

        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => AccountMatcher::SAGLAYICI,
            'subject' => $konu,
            'email' => 'kisi@ornek.test',
        ]);

        return $kullanici;
    }

    public function test_merkez_giremez_derse_hesap_kapanir_ve_oturum_biter(): void
    {
        $kullanici = $this->merkeziBagliKullanici();

        Http::fake(['merkez.test/api/kimlik-durumu' => Http::response([
            'kimlikler' => ['kimlik-1' => ['durum' => 'askida', 'girebilir' => false]],
        ])]);

        $this->actingAs($kullanici)->get(route('yedek.index'))->assertRedirect(route('login'));

        $this->assertFalse($kullanici->fresh()->aktif);
        $this->assertGuest();
    }

    public function test_merkez_erisilemezse_kimse_atilmaz(): void
    {
        $kullanici = $this->merkeziBagliKullanici();

        /* Merkez düştüğü gün buraya girilebilmeli: "sorulamadı" ≠ "erişimi yok". */
        Http::fake(['merkez.test/api/kimlik-durumu' => Http::response('', 500)]);

        $this->actingAs($kullanici)->get(route('yedek.index'))->assertOk();

        $this->assertTrue($kullanici->fresh()->aktif);
    }

    public function test_yanitta_anilmayan_kimlige_dokunulmaz(): void
    {
        $kullanici = $this->merkeziBagliKullanici();

        /* Merkez o kimliği hiç anmadıysa elimizde bilgi yok demektir. */
        Http::fake(['merkez.test/api/kimlik-durumu' => Http::response(['kimlikler' => []])]);

        $this->actingAs($kullanici)->get(route('yedek.index'))->assertOk();

        $this->assertTrue($kullanici->fresh()->aktif);
    }

    public function test_merkez_geri_acinca_hesap_da_acilir(): void
    {
        $kullanici = $this->merkeziBagliKullanici();
        $kullanici->forceFill(['aktif' => false])->save();

        Http::fake(['merkez.test/api/kimlik-durumu' => Http::response([
            /* Rol de gerekiyor: merkez "girebilir" dese bile rolü izinli olmalı. */
            'kimlikler' => ['kimlik-1' => ['durum' => 'etkin', 'girebilir' => true, 'rol' => 'muhasebe']],
        ])]);

        $this->artisan('kimlik:esitle')->assertSuccessful();

        $this->assertTrue($kullanici->fresh()->aktif);
    }

    public function test_merkeze_hic_baglanmamis_hesap_sorulmaz(): void
    {
        $kullanici = User::factory()->yedekci()->create();

        Http::fake();

        $this->actingAs($kullanici)->get(route('yedek.index'))->assertOk();

        /*
         * "Hiç istek gitmesin" DENMEZ: sayfanın başka istekleri buranın
         * konusu değil. Sınamanın iddiası dar: merkezle bağı olmayan hesap
         * için MERKEZE sorulmaz.
         */
        Http::assertNotSent(fn ($istek) => str_contains($istek->url(), 'merkez.test'));
    }
}
