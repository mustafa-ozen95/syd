<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\ConsoleModule\Contracts\ConfigurationSource;
use App\Models\ConfigSablonu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Kurulum sayfasındaki config şablonları ve Konsol'daki görünüşleri. */
final class ConfigSablonTest extends TestCase
{
    use RefreshDatabase;

    private function dosya(string $ad = 'sifir.conf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($ad, (string) file_get_contents(base_path('tests/Fixtures/junos/ornek-ex.conf')));
    }

    #[Test]
    public function yonetici_ekler_yeniden_adlandirir_dosyasini_degistirir_ve_siler(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->post(route('setup.sablon.store'), ['ad' => 'Sıfır Config (Default Vlan)', 'dosya' => $this->dosya()])
            ->assertSessionHasNoErrors();
        $s = ConfigSablonu::sole();
        $this->assertSame('sifir.conf', $s->dosya);
        $this->assertStringNotContainsString('host-name', (string) DB::table('config_sablonlari')->value('metin'), 'metin şifreli');

        $this->actingAs($yonetici)->get(route('setup.index'))->assertOk()->assertSee('Sıfır Config (Default Vlan)');
        $this->actingAs($yonetici)->get(route('setup.sablon.download', $s))->assertOk()->assertSee('host-name sw-ornek.example.test', false);

        /* Yalnız ad değişir, dosya kalır; sonra dosya değişir. */
        $this->actingAs($yonetici)->put(route('setup.sablon.update', $s), ['ad' => 'Sıfır Config'])->assertSessionHasNoErrors();
        $this->assertSame(['Sıfır Config', 'sifir.conf'], [$s->refresh()->ad, $s->dosya]);
        $this->actingAs($yonetici)->put(route('setup.sablon.update', $s), ['ad' => 'Sıfır Config', 'dosya' => $this->dosya('yeni.conf.txt')])->assertSessionHasNoErrors();
        $this->assertSame('yeni.conf.txt', $s->refresh()->dosya);

        /* Junos yapılandırması olmayan dosya reddedilir. */
        $this->actingAs($yonetici)->post(route('setup.sablon.store'), ['ad' => 'Bozuk', 'dosya' => UploadedFile::fake()->createWithContent('x.txt', 'merhaba')])
            ->assertSessionHasErrors('dosya');

        $this->actingAs($yonetici)->delete(route('setup.sablon.destroy', $s))->assertRedirect();
        $this->assertSame(0, ConfigSablonu::query()->count());
    }

    #[Test]
    public function yetkisiz_kisi_degistiremez(): void
    {
        $s = ConfigSablonu::create(['ad' => 'Şablon', 'dosya' => 'a.conf', 'metin' => "system {\n}\n", 'boyut' => 11]);
        $kisi = User::factory()->create();

        $this->actingAs($kisi)->post(route('setup.sablon.store'), ['ad' => 'X', 'dosya' => $this->dosya()])->assertRedirect(route('forbidden'));
        $this->actingAs($kisi)->put(route('setup.sablon.update', $s), ['ad' => 'Y'])->assertRedirect(route('forbidden'));
        $this->actingAs($kisi)->delete(route('setup.sablon.destroy', $s))->assertRedirect(route('forbidden'));
        $this->actingAs($kisi)->get(route('setup.sablon.download', $s))->assertRedirect(route('forbidden'));
    }

    #[Test]
    public function konsolda_sablonlar_en_ustte_cihaz_gibi_gorunur(): void
    {
        $s = ConfigSablonu::create(['ad' => 'Sıfır Config', 'dosya' => 'sifir.conf', 'metin' => "system {\n    host-name dgn;\n}\n", 'boyut' => 30]);
        $kaynak = app(ConfigurationSource::class);

        $this->assertSame('Sıfır Config', $kaynak->cihazlar()[0]);
        $this->assertSame('sifir.conf', $kaynak->yedekler('Sıfır Config')[0]['dosya']);
        $this->assertStringContainsString('host-name dgn;', (string) $kaynak->icerik('Sıfır Config', 'sifir.conf'));
    }
}
