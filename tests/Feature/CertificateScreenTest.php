<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Certificate\CertificateType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sertifika ekranı: panel istek bırakır, sunucu koşturur.
 *
 * Buradaki asıl kural, ekranın güzelliği değil: PANEL SERTİFİKA ALMAZ.
 * Yayılmamış bir kayıtla "tamamla" denemesi engellenir — o deneme başarısız
 * sayılır ve tur baştan başlar.
 */
class CertificateScreenTest extends TestCase
{
    use RefreshDatabase;

    /** Dosya sistemine ve DNS'e çıkmayan sahte durum. */
    private function sahte(array $yayilma, bool $birakildi = true): CertificateType
    {
        return new class($yayilma, $birakildi) extends CertificateType
        {
            public ?string $sonEylem = null;

            public function __construct(private array $yayilmaListesi, private bool $birakildi) {}

            public function durum(): ?array
            {
                return ['veren' => "Let's Encrypt", 'bitis' => 'Jan  1 00:00:00 2030 GMT', 'kalan_gun' => 89];
            }

            public function kayitlar(): ?array
            {
                return array_map(fn (array $k): array => ['ad' => $k['ad'], 'deger' => $k['deger']], $this->yayilmaListesi);
            }

            public function sonIslem(): ?array
            {
                return null;
            }

            public function istekBekliyorMu(): bool
            {
                return false;
            }

            public function yayilmaDurumu(): array
            {
                return $this->yayilmaListesi;
            }

            public function istekBirak(string $eylem): bool
            {
                $this->sonEylem = $eylem;

                return $this->birakildi;
            }
        };
    }

    public function test_ekran_yalnizca_yoneticide_acilir(): void
    {
        $this->actingAs(User::factory()->rol('muhasebe')->create())
            ->get(route('admin.certificate.index'))
            ->assertRedirect(route('forbidden'));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.certificate.index'))
            ->assertOk()
            ->assertSee('Şu anki sertifika');
    }

    public function test_kayitlar_ve_yayilma_durumu_gosterilir(): void
    {
        $this->instance(CertificateType::class, $this->sahte([
            ['ad' => '_acme-challenge.syd.ornek.test', 'deger' => 'abc123', 'yayildi' => true],
            ['ad' => '_acme-challenge.yedek.ornek.test', 'deger' => 'def456', 'yayildi' => false],
        ]));

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.certificate.index'))
            ->assertOk()
            ->assertSee('_acme-challenge.syd.ornek.test')
            ->assertSee('abc123')
            ->assertSee('görünüyor')
            ->assertSee('henüz yok');
    }

    /**
     * YAYILMAMIŞ KAYITLA TAMAMLANMAZ.
     *
     * Sertifika sağlayıcısına yayılmamış bir kayıtla gitmek başarısız bir
     * deneme demektir; tur baştan başlar ve kullanıcı neden olduğunu
     * anlamaz.
     */
    public function test_yayilmamis_kayit_varken_onay_reddedilir(): void
    {
        $sahte = $this->sahte([
            ['ad' => '_acme-challenge.syd.ornek.test', 'deger' => 'abc123', 'yayildi' => false],
        ]);

        $this->instance(CertificateType::class, $sahte);

        $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.certificate.index'))
            ->post(route('admin.certificate.complete'))
            ->assertRedirect(route('admin.certificate.index'))
            ->assertSessionHasErrors('sertifika');

        $this->assertNull($sahte->sonEylem, 'Sunucuya istek gitmemeliydi.');
    }

    public function test_kayitlar_yayildiysa_istek_birakilir(): void
    {
        $sahte = $this->sahte([
            ['ad' => '_acme-challenge.syd.ornek.test', 'deger' => 'abc123', 'yayildi' => true],
        ]);

        $this->instance(CertificateType::class, $sahte);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.certificate.complete'))
            ->assertRedirect();

        $this->assertSame('tamamla', $sahte->sonEylem);
    }

    public function test_tur_baslatmak_istek_birakir(): void
    {
        $sahte = $this->sahte([]);

        $this->instance(CertificateType::class, $sahte);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.certificate.start'))
            ->assertRedirect();

        $this->assertSame('baslat', $sahte->sonEylem);
    }

    /**
     * İSTEK BIRAKILAMAZSA BAŞARI DENMEZ.
     *
     * İstek dizini yazılamıyorsa (ör. root'a aitse) sunucudaki ajan hiçbir
     * şey görmez. Ekran "tur başlatıldı" derse kullanıcı boşuna bekler; hata
     * gösterilir ve işlem kaydına başarı düşülmez.
     */
    public function test_istek_birakilamazsa_tur_baslatildi_denmez(): void
    {
        $sahte = $this->sahte([], birakildi: false);
        $this->instance(CertificateType::class, $sahte);
        $yonetici = User::factory()->admin()->create();
        $once = ActivityLog::count();

        $this->actingAs($yonetici)
            ->from(route('admin.certificate.index'))
            ->post(route('admin.certificate.start'))
            ->assertRedirect(route('admin.certificate.index'))
            ->assertSessionHasErrors('sertifika')
            ->assertSessionMissing('status');

        $this->assertSame('baslat', $sahte->sonEylem);
        $this->assertSame($once, ActivityLog::count(), 'Başarısız istek işlem kaydına düşmemeli.');
    }

    public function test_istek_birakilamazsa_sertifika_aliniyor_denmez(): void
    {
        $sahte = $this->sahte([
            ['ad' => '_acme-challenge.syd.ornek.test', 'deger' => 'abc123', 'yayildi' => true],
        ], birakildi: false);
        $this->instance(CertificateType::class, $sahte);
        $yonetici = User::factory()->admin()->create();
        $once = ActivityLog::count();

        $this->actingAs($yonetici)
            ->from(route('admin.certificate.index'))
            ->post(route('admin.certificate.complete'))
            ->assertRedirect(route('admin.certificate.index'))
            ->assertSessionHasErrors('sertifika')
            ->assertSessionMissing('status');

        $this->assertSame('tamamla', $sahte->sonEylem);
        $this->assertSame($once, ActivityLog::count(), 'Başarısız istek işlem kaydına düşmemeli.');
    }

    /** Hata ekranda bir kez görünür: bildirim bloğu doğrulama hatalarını zaten basıyor. */
    public function test_iletilemedi_hatasi_ekranda_bir_kez_gorunur(): void
    {
        $this->instance(CertificateType::class, $this->sahte([], birakildi: false));

        $yanit = $this->actingAs(User::factory()->admin()->create())
            ->from(route('admin.certificate.index'))
            ->followingRedirects()
            ->post(route('admin.certificate.start'))
            ->assertOk();

        $this->assertSame(1, substr_count($yanit->getContent(), 'İstek sunucuya iletilemedi'));
    }
}
