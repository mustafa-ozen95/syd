<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Güvenlik başlıkları GERÇEKTEN gidiyor mu?
 *
 * "nginx bunları zaten koyuyor" gibi bir varsayım, yapılandırma değişince
 * sessizce yanlışa döner. Bir katmanın varlığı yorumla değil ölçümle
 * bilinir — bu sınama o ölçümdür.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_giris_sayfasi_basliklari_tasir(): void
    {
        $yanit = $this->get(route('login'));

        $yanit->assertOk();

        foreach (array_keys(config('security.headers')) as $baslik) {
            $yanit->assertHeader($baslik);
        }

        $this->assertStringContainsString(
            "default-src 'self'",
            (string) $yanit->headers->get('Content-Security-Policy'),
        );
    }

    public function test_yazi_tipi_kaynagi_engellenmez(): void
    {
        /*
         * Fontlar Google Fonts'tan geliyor (şablonun yolu). CSP'de karşılığı
         * yoksa tarayıcı hem stil dosyasını hem font dosyalarını SESSİZCE
         * engeller: sayfa açılır, yalnızca yazı tipi sistem fontuna düşer ve
         * sebebi hiçbir yerde görünmez.
         */
        $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("style-src 'self' https://fonts.googleapis.com", $csp);
        $this->assertStringContainsString("font-src 'self' https://fonts.gstatic.com", $csp);
    }

    public function test_giris_sayfasi_yazi_tipini_yukler(): void
    {
        /* Bağlantı düzenin <head>'inde; kaybolursa font hiç yüklenmez. */
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('fonts.googleapis.com/css2?family=Public+Sans', false);
    }

    public function test_oturumlu_sayfa_diske_yazilmaz(): void
    {
        $yanit = $this->actingAs(User::factory()->yedekci()->create())->get(route('backup.index'));

        $yanit->assertOk();
        $this->assertStringContainsString(
            'no-store',
            (string) $yanit->headers->get('Cache-Control'),
        );
    }

    public function test_giris_sayfasinda_no_store_aranmaz(): void
    {
        /* Oturumsuz sayfanın saklanacak bir şeyi yok; kural yalnızca oturumluda. */
        $yanit = $this->get(route('login'));

        $this->assertStringNotContainsString(
            'no-store',
            (string) $yanit->headers->get('Cache-Control'),
        );
    }
}
