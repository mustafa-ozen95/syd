<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\BasHarfler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hesap simgesindeki baş harfler BÜTÜN PANELLERDE AYNI olmalı.
 *
 * Kural: ilk iki KELİMENİN baş harfi ("Sınama Ağ" → "SA"); adın ilk iki
 * HARFİ ("SI") alınmaz. Simge bir kimlik işareti: panelden panele değişince
 * "burada başka biri miyim" sorusunu doğuruyor.
 */
class BasHarflerTest extends TestCase
{
    use RefreshDatabase;

    public function test_iki_kelimenin_bas_harfi_alinir(): void
    {
        $this->assertSame('SA', BasHarfler::al('Sınama Ağ'));
        $this->assertSame('AY', BasHarfler::al('Ayşe Yılmaz'));
    }

    /** Tek kelimeyse tek harf; iki harfe TAMAMLANMAZ. */
    public function test_tek_kelime_tek_harf(): void
    {
        $this->assertSame('A', BasHarfler::al('Ali'));
    }

    /** Üçüncü kelime alınmaz: simge iki harften uzun olmamalı. */
    public function test_ucuncu_kelime_alinmaz(): void
    {
        $this->assertSame('AD', BasHarfler::al('Ali Demir Kaya'));
    }

    /** Fazla boşluk ve boş ad ekranı bozmaz. */
    public function test_bosluklar_ve_bos_ad(): void
    {
        $this->assertSame('AD', BasHarfler::al('  Ali   Demir  '));
        $this->assertSame('?', BasHarfler::al(''));
        $this->assertSame('?', BasHarfler::al(null));
    }

    /** Ekranda gerçekten bu çiziliyor. */
    public function test_ust_cubukta_gorunur(): void
    {
        $kullanici = User::factory()->admin()->create(['name' => 'Sınama Ağ']);

        $this->actingAs($kullanici)
            ->get(route('profil.show'))
            ->assertOk()
            ->assertSee('>SA<', false);
    }
}
