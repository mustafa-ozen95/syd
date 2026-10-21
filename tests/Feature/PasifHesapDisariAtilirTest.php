<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Erişimi kaldırılan hesabın AÇIK OTURUMU da biter.
 *
 * `aktif` yalnızca girişte sorulduğu sürece, o sırada oturumu açık olan biri
 * sekmesini kapatmadığı sürece panelde gezinmeye devam ediyordu.
 */
class PasifHesapDisariAtilirTest extends TestCase
{
    use RefreshDatabase;

    public function test_pasife_alinan_kullanici_bir_sonraki_istekte_atilir(): void
    {
        $kullanici = User::factory()->yedekci()->create();

        $this->actingAs($kullanici)->get(route('yedek.index'))->assertOk();

        $kullanici->forceFill(['aktif' => false])->save();

        $this->get(route('yedek.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_etkin_kullanici_etkilenmez(): void
    {
        $kullanici = User::factory()->yedekci()->create();

        $this->actingAs($kullanici)->get(route('yedek.index'))->assertOk();
        $this->get(route('yedek.index'))->assertOk();

        $this->assertAuthenticatedAs($kullanici);
    }
}
