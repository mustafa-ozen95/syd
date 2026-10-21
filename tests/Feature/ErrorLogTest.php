<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ErrorLog;
use App\Services\ErrorLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Hatalar panele de düşer.
 *
 * Dosya günlüğü sunucuya girmeyi gerektiriyor; kuyrukta patlayan bir işte
 * kullanıcıya yalnızca genel bir hata dönüyor ve sebebi başka hiçbir yerde
 * görünmüyor.
 */
class ErrorLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_hata_veritabanina_yazilir(): void
    {
        app(ErrorLogger::class)->record(new RuntimeException('Dış servis yanıt vermedi'));

        $this->assertDatabaseHas('error_logs', [
            'type' => RuntimeException::class,
            'message' => 'Dış servis yanıt vermedi',
            'occurrences' => 1,
        ]);
    }

    public function test_ayni_hata_yeni_satir_acmaz_sayac_artar(): void
    {
        $logger = app(ErrorLogger::class);

        /* Aynı satırdan üretilsin: imza tür|mesaj|dosya|satır. */
        $hata = new RuntimeException('tekrar eden');
        $logger->record($hata);
        $logger->record($hata);
        $logger->record($hata);

        $this->assertSame(1, ErrorLog::count());
        $this->assertSame(3, (int) ErrorLog::first()->occurrences);
    }

    public function test_dogrulama_ve_yetki_hatalari_yazilmaz(): void
    {
        /*
         * Bunlar uygulamanın NORMAL çalışması; listeye koymak listeyi
         * kullanılmaz hâle getirirdi. Yetki reddi zaten denetim kaydında.
         */
        app(ErrorLogger::class)->record(ValidationException::withMessages(['a' => 'b']));
        app(ErrorLogger::class)->record(new AuthorizationException);

        $this->assertSame(0, ErrorLog::count());
    }

    public function test_yigin_izi_argumansiz_saklanir(): void
    {
        app(ErrorLogger::class)->record(new RuntimeException('iz'));

        $iz = (string) ErrorLog::first()->trace;

        /*
         * PHP'nin kendi izi çağrı argümanlarını taşıyabiliyor ve burada
         * argüman olarak API anahtarı ve kullanıcı metni geçebiliyor.
         */
        $this->assertStringContainsString('#0 ', $iz);
        $this->assertStringNotContainsString("'", $iz);
    }
}
