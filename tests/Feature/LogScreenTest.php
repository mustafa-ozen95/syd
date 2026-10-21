<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\ErrorLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Kayıt ekranı GERÇEKTEN var ve YALNIZCA yöneticide.
 *
 * Kayıt tutmak tek başına yetmiyor: kimse bakamıyorsa kayıt yok sayılır.
 */
class LogScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_yonetici_denetim_kayitlarini_gorur(): void
    {
        $yonetici = User::factory()->admin()->create();
        app(ActivityRecorder::class)->kaydet('deneme', 'Görünmesi gereken satır', null, $yonetici);

        $this->actingAs($yonetici)
            ->get(route('admin.kayitlar.denetim'))
            ->assertOk()
            ->assertSee('Görünmesi gereken satır')
            ->assertSee('Denetim kayıtları');
    }

    public function test_yonetici_olmayan_goremez(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.kayitlar.denetim'))
            ->assertRedirect(route('yetkisiz'));
    }

    public function test_zincir_ekrandan_dogrulanabilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        app(ActivityRecorder::class)->kaydet('deneme', 'bir', null, $yonetici);

        $this->actingAs($yonetici)
            ->post(route('admin.kayitlar.dogrula'))
            ->assertRedirect(route('admin.kayitlar.denetim'))
            ->assertSessionHas('status');

        /* Rozet artık son doğrulamayı okuyabiliyor. */
        $this->get(route('admin.kayitlar.denetim'))
            ->assertOk()
            ->assertSee('Kayıt bütünlüğü doğrulandı');
    }

    public function test_kirik_zincir_ekranda_soylenir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $kayit = app(ActivityRecorder::class);
        $kayit->kaydet('deneme', 'bir', null, $yonetici);
        $hedef = $kayit->kaydet('deneme', 'iki', null, $yonetici);

        ActivityLog::query()->whereKey($hedef->getKey())->update(['aciklama' => 'kurcalandı']);

        $this->actingAs($yonetici)
            ->post(route('admin.kayitlar.dogrula'))
            ->assertSessionHas('error');

        $this->get(route('admin.kayitlar.denetim'))
            ->assertOk()
            ->assertSee('KAYIT DEĞİŞTİRİLMİŞ');
    }

    public function test_hata_kayitlari_ekranda(): void
    {
        $yonetici = User::factory()->admin()->create();
        app(ErrorLogger::class)->record(new RuntimeException('Dış servis yanıt vermedi'));

        $this->actingAs($yonetici)
            ->get(route('admin.kayitlar.hatalar'))
            ->assertOk()
            ->assertSee('Dış servis yanıt vermedi');
    }
}
