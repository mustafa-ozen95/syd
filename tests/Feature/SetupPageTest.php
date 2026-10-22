<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\JunosImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Junos TFTP yerel kurulum sayfası ve listenin panelden yönetimi.
 */
final class SetupPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('junos');
    }

    private function rolu(string $slug): User
    {
        return User::factory()->rol($slug)->create();
    }

    #[Test]
    public function oturumsuz_giremez(): void
    {
        $this->get(route('setup.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function on_modelin_komutu_gocle_gelir_ve_listelenir(): void
    {
        $this->assertSame(10, JunosImage::count());

        $this->actingAs($this->rolu('network'))
            ->get(route('setup.index'))
            ->assertOk()
            ->assertSee('install --format tftp://192.0.2.5/jinstall-ex-2200-12.3R12.4-domestic-signed.tgz', false)
            ->assertSee('install --format tftp://192.0.2.5/junos-vmhost-install-mx-x86-64-23.4R2-S8.7.tgz', false)
            ->assertSee('Jloader-ex-3242')
            /* network görür ama yönetemez. */
            ->assertDontSee('data-modal-open="image-add"', false)
            ->assertDontSee('Görüntüyü sil');
    }

    #[Test]
    public function yetkisi_olmayan_rol_sayfayi_goremez(): void
    {
        $this->actingAs($this->rolu('teknik'))->get(route('setup.index'))->assertRedirect(route('forbidden'));
    }

    #[Test]
    public function yonetici_ekler_duzenler_siler_ve_kayda_gecer(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->get(route('setup.index'))
            ->assertSee('data-modal-open="image-add"', false);

        $this->actingAs($yonetici)->post(route('setup.image.store'), [
            'model' => 'EX-4300', 'surum' => '21.4', 'dosya' => 'jinstall-ex-4300-21.4R3.15-signed.tgz',
        ])->assertRedirect(route('setup.index'));

        $yeni = JunosImage::where('model', 'EX-4300')->sole();
        $this->assertGreaterThan(JunosImage::where('model', 'MX')->value('sira'), $yeni->sira, 'sıra boşsa sona eklenir');

        $this->actingAs($yonetici)->get(route('setup.index'))
            ->assertSee('install --format tftp://192.0.2.5/jinstall-ex-4300-21.4R3.15-signed.tgz', false);

        $this->actingAs($yonetici)->put(route('setup.image.update', $yeni), [
            'model' => 'EX-4300', 'surum' => '22.4', 'dosya' => 'jinstall-ex-4300-22.4R3.25-signed.tgz', 'sira' => 5,
        ])->assertRedirect(route('setup.index'));

        $this->assertSame('22.4', $yeni->fresh()->surum);
        $this->assertSame(5, $yeni->fresh()->sira);

        $this->actingAs($yonetici)->delete(route('setup.image.destroy', $yeni))->assertRedirect(route('setup.index'));
        $this->assertModelMissing($yeni);

        $this->assertSame(
            ['junos_ekle', 'junos_duzenle', 'junos_sil'],
            ActivityLog::whereIn('tur', ['junos_ekle', 'junos_duzenle', 'junos_sil'])->orderBy('id')->pluck('tur')->all(),
        );
    }

    /** Dosya adı kopyalanıp switch'e yapıştırılan komutun parçası. */
    #[Test]
    public function dosya_adina_komut_sizdirilamaz(): void
    {
        $yonetici = User::factory()->admin()->create();

        foreach (['a.tgz; request system zeroize', '../etc/passwd', 'a b.tgz', 'x.tgz`id`'] as $kotu) {
            $this->actingAs($yonetici)->from(route('setup.index'))->post(route('setup.image.store'), [
                'model' => 'EX', 'surum' => '1', 'dosya' => $kotu, '_form' => 'ekle',
            ])->assertSessionHasErrors('dosya');
        }

        $this->assertSame(10, JunosImage::count());
    }

    #[Test]
    public function yonetim_yetkisi_olmayan_yazamaz(): void
    {
        $goruntu = JunosImage::first();

        $this->actingAs($this->rolu('network'))
            ->post(route('setup.image.store'), ['model' => 'X', 'surum' => '1', 'dosya' => 'x.tgz'])
            ->assertRedirect(route('forbidden'));
        $this->actingAs($this->rolu('network'))->delete(route('setup.image.destroy', $goruntu))->assertRedirect(route('forbidden'));

        $this->assertModelExists($goruntu);
    }

    /** Hatalı gönderimden sonra yalnız o pencere eski girdiyle açık gelir. */
    #[Test]
    public function hatali_duzenleme_kendi_penceresinde_acilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        [$ilk, $ikinci] = JunosImage::query()->sirali()->take(2)->get()->all();

        $this->actingAs($yonetici)->from(route('setup.index'))->followingRedirects()
            ->put(route('setup.image.update', $ilk), [
                'model' => 'EX-2200', 'surum' => '12', 'dosya' => 'kotu ad.tgz', '_form' => 'duzenle-'.$ilk->id,
            ])
            ->assertOk()
            ->assertSee('value="kotu ad.tgz"', false)
            ->assertSee('value="'.$ikinci->dosya.'"', false);
    }

    #[Test]
    public function dosyasi_tftpde_olmayan_satir_isaretlenir(): void
    {
        $var = JunosImage::query()->sirali()->first();
        Storage::disk('junos')->put($var->dosya, str_repeat('x', 2048));

        $this->actingAs($this->rolu('network'))->get(route('setup.index'))
            ->assertOk()
            ->assertSee('2.0 KB')
            ->assertSee("TFTP'de yok", false);
    }

    #[Test]
    public function yonetici_dosyayi_yukler_ad_bossa_yuklenenin_adi_alinir(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->post(route('setup.image.store'), [
            'model' => 'EX-4300', 'surum' => '21.4', 'dosya' => '',
            'goruntu' => UploadedFile::fake()->create('jinstall-ex-4300-21.4R3.15-signed.tgz', 300),
        ])->assertRedirect(route('setup.index'));

        $this->assertSame('jinstall-ex-4300-21.4R3.15-signed.tgz', JunosImage::where('model', 'EX-4300')->value('dosya'));
        Storage::disk('junos')->assertExists('jinstall-ex-4300-21.4R3.15-signed.tgz');
        $this->assertSame([], array_values(array_filter(
            Storage::disk('junos')->allFiles(), fn (string $f): bool => str_starts_with($f, '.yukleniyor-'),
        )), 'geçici dosya kalmaz');
    }

    /** Yüklenen dosyanın adı da komutun parçası olur: ad kuralı ona da işler. */
    #[Test]
    public function yuklenen_dosyanin_adi_da_denetlenir(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->from(route('setup.index'))->post(route('setup.image.store'), [
            'model' => 'EX', 'surum' => '1', '_form' => 'ekle',
            'goruntu' => UploadedFile::fake()->create('kotu ad;id.tgz', 10),
        ])->assertSessionHasErrors('dosya');

        $this->assertSame([], Storage::disk('junos')->allFiles());
        $this->assertSame(10, JunosImage::count());
    }

    #[Test]
    public function ad_degisince_tftpdeki_dosyanin_adi_da_degisir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $g = JunosImage::where('dosya', 'jloader-ex-3242-12.1R3-signed.tgz')->sole();
        Storage::disk('junos')->put($g->dosya, 'loader');

        $this->actingAs($yonetici)->put(route('setup.image.update', $g), [
            'model' => $g->model, 'surum' => $g->surum, 'dosya' => 'jloader-yeni.tgz', 'sira' => $g->sira,
        ])->assertRedirect(route('setup.index'));

        Storage::disk('junos')->assertMissing('jloader-ex-3242-12.1R3-signed.tgz');
        $this->assertSame('loader', Storage::disk('junos')->get('jloader-yeni.tgz'));
    }

    #[Test]
    public function yeni_dosya_yuklenince_eskisinin_yerine_gecer(): void
    {
        $yonetici = User::factory()->admin()->create();
        $g = JunosImage::where('dosya', 'jloader-ex-3242-12.1R3-signed.tgz')->sole();
        Storage::disk('junos')->put($g->dosya, 'eski');

        $this->actingAs($yonetici)->put(route('setup.image.update', $g), [
            'model' => $g->model, 'surum' => '12.2', 'dosya' => 'jloader-ex-3242-12.2-signed.tgz', 'sira' => $g->sira,
            'goruntu' => UploadedFile::fake()->createWithContent('herhangi.tgz', 'yeni'),
        ])->assertRedirect(route('setup.index'));

        Storage::disk('junos')->assertMissing('jloader-ex-3242-12.1R3-signed.tgz');
        $this->assertSame('yeni', Storage::disk('junos')->get('jloader-ex-3242-12.2-signed.tgz'));
    }

    /** EX-4500 ile EX-4550 gibi iki satır aynı dosyayı gösterebilir. */
    #[Test]
    public function silinen_satirin_dosyasi_baskasi_kullanmiyorsa_silinir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $ortak = JunosImage::where('dosya', 'jloader-ex-3242-12.1R3-signed.tgz')->sole();
        $ikiz = JunosImage::create(['model' => 'EX-3242B', 'surum' => '12.1R3', 'dosya' => $ortak->dosya, 'sira' => 999]);
        Storage::disk('junos')->put($ortak->dosya, 'loader');

        $this->actingAs($yonetici)->delete(route('setup.image.destroy', $ikiz))->assertRedirect(route('setup.index'));
        Storage::disk('junos')->assertExists($ortak->dosya);

        $this->actingAs($yonetici)->delete(route('setup.image.destroy', $ortak))->assertRedirect(route('setup.index'));
        Storage::disk('junos')->assertMissing($ortak->dosya);
    }

    #[Test]
    public function yonetim_yetkisi_olmayan_dosya_yukleyemez(): void
    {
        $this->actingAs($this->rolu('network'))->post(route('setup.image.store'), [
            'model' => 'X', 'surum' => '1', 'goruntu' => UploadedFile::fake()->create('x.tgz', 10),
        ])->assertRedirect(route('forbidden'));

        $this->assertSame([], Storage::disk('junos')->allFiles());
    }
}
