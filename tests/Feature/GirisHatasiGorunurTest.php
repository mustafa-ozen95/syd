<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Giriş ekranı NEDEN giremediğini söyler.
 *
 * Yönlendirmeyle dönen hatalar (merkezden red, pasif hesap, doğrulama)
 * oturumdaki torbada duruyor ve şablon onu hiç çizmiyordu: kullanıcı bomboş
 * bir giriş sayfası görüyor ve sebebini bilmiyordu.
 */
class GirisHatasiGorunurTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasife_alinan_kullaniciya_sebebi_soylenir(): void
    {
        $kullanici = User::factory()->yedekci()->create();

        $this->actingAs($kullanici)->get(route('yedek.index'))->assertOk();

        $kullanici->forceFill(['aktif' => false])->save();

        /* Gerçek akış: ara katman dışarı atıyor, giriş ekranı sebebi yazıyor. */
        $this->followingRedirects()
            ->get(route('yedek.index'))
            ->assertOk()
            ->assertSee('Hesabınız etkin değil', false);
    }

    public function test_hata_yokken_uyari_kutusu_cizilmez(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('alert--danger');
    }
}
