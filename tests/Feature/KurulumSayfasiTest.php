<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\JunosGoruntusu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Junos TFTP yerel kurulum sayfası ve listenin panelden yönetimi.
 */
final class KurulumSayfasiTest extends TestCase
{
    use RefreshDatabase;

    private function rolu(string $slug): User
    {
        return User::factory()->rol($slug)->create();
    }

    #[Test]
    public function oturumsuz_giremez(): void
    {
        $this->get(route('kurulum.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function on_modelin_komutu_gocle_gelir_ve_listelenir(): void
    {
        $this->assertSame(10, JunosGoruntusu::count());

        $this->actingAs($this->rolu('network'))
            ->get(route('kurulum.index'))
            ->assertOk()
            ->assertSee('install --format tftp://192.0.2.5/jinstall-ex-2200-12.3R12.4-domestic-signed.tgz', false)
            ->assertSee('install --format tftp://192.0.2.5/junos-vmhost-install-mx-x86-64-23.4R2-S8.7.tgz', false)
            ->assertSee('Jloader-ex-3242')
            /* network görür ama yönetemez. */
            ->assertDontSee('data-modal-open="goruntu-ekle"', false)
            ->assertDontSee('Görüntüyü sil');
    }

    #[Test]
    public function yetkisi_olmayan_rol_sayfayi_goremez(): void
    {
        $this->actingAs($this->rolu('teknik'))->get(route('kurulum.index'))->assertRedirect(route('yetkisiz'));
    }

    #[Test]
    public function yonetici_ekler_duzenler_siler_ve_kayda_gecer(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->get(route('kurulum.index'))
            ->assertSee('data-modal-open="goruntu-ekle"', false);

        $this->actingAs($yonetici)->post(route('kurulum.goruntu.store'), [
            'model' => 'EX-4300', 'surum' => '21.4', 'dosya' => 'jinstall-ex-4300-21.4R3.15-signed.tgz',
        ])->assertRedirect(route('kurulum.index'));

        $yeni = JunosGoruntusu::where('model', 'EX-4300')->sole();
        $this->assertGreaterThan(JunosGoruntusu::where('model', 'MX')->value('sira'), $yeni->sira, 'sıra boşsa sona eklenir');

        $this->actingAs($yonetici)->get(route('kurulum.index'))
            ->assertSee('install --format tftp://192.0.2.5/jinstall-ex-4300-21.4R3.15-signed.tgz', false);

        $this->actingAs($yonetici)->put(route('kurulum.goruntu.update', $yeni), [
            'model' => 'EX-4300', 'surum' => '22.4', 'dosya' => 'jinstall-ex-4300-22.4R3.25-signed.tgz', 'sira' => 5,
        ])->assertRedirect(route('kurulum.index'));

        $this->assertSame('22.4', $yeni->fresh()->surum);
        $this->assertSame(5, $yeni->fresh()->sira);

        $this->actingAs($yonetici)->delete(route('kurulum.goruntu.destroy', $yeni))->assertRedirect(route('kurulum.index'));
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
            $this->actingAs($yonetici)->from(route('kurulum.index'))->post(route('kurulum.goruntu.store'), [
                'model' => 'EX', 'surum' => '1', 'dosya' => $kotu, '_form' => 'ekle',
            ])->assertSessionHasErrors('dosya');
        }

        $this->assertSame(10, JunosGoruntusu::count());
    }

    #[Test]
    public function yonetim_yetkisi_olmayan_yazamaz(): void
    {
        $goruntu = JunosGoruntusu::first();

        $this->actingAs($this->rolu('network'))
            ->post(route('kurulum.goruntu.store'), ['model' => 'X', 'surum' => '1', 'dosya' => 'x.tgz'])
            ->assertRedirect(route('yetkisiz'));
        $this->actingAs($this->rolu('network'))->delete(route('kurulum.goruntu.destroy', $goruntu))->assertRedirect(route('yetkisiz'));

        $this->assertModelExists($goruntu);
    }

    /** Hatalı gönderimden sonra yalnız o pencere eski girdiyle açık gelir. */
    #[Test]
    public function hatali_duzenleme_kendi_penceresinde_acilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        [$ilk, $ikinci] = JunosGoruntusu::query()->sirali()->take(2)->get()->all();

        $this->actingAs($yonetici)->from(route('kurulum.index'))->followingRedirects()
            ->put(route('kurulum.goruntu.update', $ilk), [
                'model' => 'EX-2200', 'surum' => '12', 'dosya' => 'kotu ad.tgz', '_form' => 'duzenle-'.$ilk->id,
            ])
            ->assertOk()
            ->assertSee('value="kotu ad.tgz"', false)
            ->assertSee('value="'.$ikinci->dosya.'"', false);
    }
}
