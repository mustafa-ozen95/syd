<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Yedek\YedekDeposu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Yedek ekranı.
 *
 * Bu panelin tek işi: cihazların bıraktığı yapılandırma yedeklerini
 * göstermek ve indirtmek. Dosyalar yıllar içinde birikmiş TEK KOPYA, o
 * yüzden buradaki sınamaların çoğu "ne yapılamayacağını" bağlıyor:
 * kök dışına çıkılamaz, yazılamaz, kimliksiz indirilemez.
 */
class YedekEkraniTest extends TestCase
{
    use RefreshDatabase;

    private string $kok;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kok = sys_get_temp_dir().'/syd-'.bin2hex(random_bytes(5));

        mkdir($this->kok.'/SW-A', 0755, true);
        mkdir($this->kok.'/SW-B', 0755, true);

        /* Adındaki damga 2018; dosyanın kendi tarihi BUGÜN. İkisi ayrışır. */
        file_put_contents($this->kok.'/SW-A/sw-a.ornek.test_juniper.conf.gz_20181006_160347', 'eski');
        file_put_contents($this->kok.'/SW-A/sw-a.ornek.test_juniper.conf.gz_20260115_090000', 'yeni');
        file_put_contents($this->kok.'/SW-B/damgasiz.conf', 'damgasız');

        config(['yedek.kok' => $this->kok]);
        $this->app->forgetInstance(YedekDeposu::class);
    }

    protected function tearDown(): void
    {
        /* Gizli dosyalar da silinsin diye GLOB_BRACE ile nokta ile başlayanlar. */
        foreach (glob($this->kok.'/*/{,.}*', GLOB_BRACE) ?: [] as $d) {
            if (is_file($d)) {
                unlink($d);
            }
        }
        foreach (glob($this->kok.'/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->kok);

        parent::tearDown();
    }

    private function kullanici(): User
    {
        return User::factory()->yedekci()->create();
    }

    public function test_cihaz_klasorleri_listelenir(): void
    {
        $this->actingAs($this->kullanici())
            ->get(route('yedek.index'))
            ->assertOk()
            ->assertSee('SW-A')
            ->assertSee('SW-B');
    }

    /**
     * TARİH DOSYANIN ADINDAN OKUNUR.
     *
     * Dosya kopyalandığında `mtime` kopyalama anına kayıyor ve 2018 yedeği
     * "bugün" görünüyordu. Sıralama da gösterim de addaki damgaya bakar.
     */
    public function test_tarih_dosya_adindan_okunur(): void
    {
        $this->actingAs($this->kullanici())
            ->get(route('yedek.klasor', 'SW-A'))
            ->assertOk()
            ->assertSee('06.10.2018')
            ->assertSee('15.01.2026');
    }

    /** En yeni yedek ÜSTTE: aranan hep odur. */
    public function test_en_yeni_yedek_ustte(): void
    {
        $govde = $this->actingAs($this->kullanici())
            ->get(route('yedek.klasor', 'SW-A'))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos((string) $govde, '20181006_160347'),
            strpos((string) $govde, '20260115_090000'),
            'En yeni yedek listenin üstünde olmalı.',
        );
    }

    /**
     * TÜR ROZETİ DAMGAYA TAKILMAZ.
     *
     * Cihazlar damgayı uzantının ARDINA ekliyor; rozete "GZ_2" diye
     * düşüyordu.
     */
    public function test_tur_rozeti_damgadan_etkilenmez(): void
    {
        $this->actingAs($this->kullanici())
            ->get(route('yedek.klasor', 'SW-A'))
            ->assertOk()
            ->assertSee('>GZ<', false)
            ->assertDontSee('GZ_2');
    }

    /** Adında damga olmayan dosya elenmez, işaretlenir. */
    public function test_damgasiz_dosya_isaretlenir(): void
    {
        $this->actingAs($this->kullanici())
            ->get(route('yedek.klasor', 'SW-B'))
            ->assertOk()
            ->assertSee('damgasiz.conf')
            ->assertSee('ad damgasız');
    }

    public function test_dosya_indirilebilir(): void
    {
        $this->actingAs($this->kullanici())
            ->get(route('yedek.indir', 'SW-B/damgasiz.conf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/octet-stream');
    }

    /**
     * KİM İNDİRDİ KAYDA GEÇER.
     *
     * Yedek dosyası bir cihazın tüm yapılandırmasıdır; kimin ne indirdiği
     * bilinmeli.
     */
    public function test_indirme_kayda_gecer(): void
    {
        $this->actingAs($this->kullanici())->get(route('yedek.indir', 'SW-B/damgasiz.conf'));

        $this->assertTrue(
            ActivityLog::query()->where('tur', 'yedek_indirildi')->exists(),
            'İndirme denetim kaydına düşmeliydi.',
        );
    }

    /**
     * KÖK DIŞINA ÇIKILAMAZ.
     *
     * Yol `..` ile yukarı tırmanmaya kalkarsa 404. Depo dizininin dışında
     * sunucunun geri kalanı var; bir dizin gezgini oraya asla uzanmamalı.
     */
    public function test_kok_disina_cikilamaz(): void
    {
        $kullanici = $this->kullanici();

        foreach (['../../etc/passwd', 'SW-A/../../../etc/passwd', '/etc/passwd'] as $kotu) {
            $this->actingAs($kullanici)
                ->get(route('yedek.indir', $kotu))
                ->assertNotFound();
        }
    }

    /** Gizli dosyalar listelenmez ve indirilemez. */
    public function test_gizli_dosya_verilmez(): void
    {
        file_put_contents($this->kok.'/SW-B/.gizli', 'sır');

        $this->actingAs($this->kullanici())
            ->get(route('yedek.klasor', 'SW-B'))
            ->assertOk()
            ->assertDontSee('.gizli');

        $this->actingAs($this->kullanici())
            ->get(route('yedek.indir', 'SW-B/.gizli'))
            ->assertNotFound();
    }

    /** Oturumsuz kimse yedek göremez. */
    public function test_oturumsuz_erisemez(): void
    {
        $this->get(route('yedek.index'))->assertRedirect(route('login'));
        $this->get(route('yedek.indir', 'SW-B/damgasiz.conf'))->assertRedirect(route('login'));
    }

    /**
     * YAZMA UCU YOK.
     *
     * Panel yalnızca okur; silme/yükleme ucu olsaydı bir hata ya da kötü
     * niyet tek kopyayı yok edebilirdi.
     */
    public function test_yazma_ucu_yoktur(): void
    {
        $yazanlar = collect(app('router')->getRoutes())
            ->filter(fn ($rota): bool => str_starts_with((string) $rota->getName(), 'yedek.'))
            ->reject(fn ($rota): bool => $rota->methods() === ['GET', 'HEAD']);

        $this->assertTrue($yazanlar->isEmpty(), 'Yedek rotaları yalnızca okuma olmalı.');
    }
}
