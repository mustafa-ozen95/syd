<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Denetim kaydı DEĞİŞTİRİLEMEZ.
 *
 * Zincirin amacı kaydı gizlemek değil, değiştirilmediğini KANITLAYABİLMEK.
 * Bu sınamalar tam da o kanıtın işlediğini gösterir: satır değiştirilirse ya
 * da silinirse doğrulama kırılmayı görür.
 */
class DenetimZinciriTest extends TestCase
{
    use RefreshDatabase;

    public function test_satirlar_birbirine_zincirlenir(): void
    {
        $kayit = app(ActivityRecorder::class);

        $bir = $kayit->kaydet('deneme', 'bir');
        $iki = $kayit->kaydet('deneme', 'iki');

        $this->assertNotNull($bir->ozet);
        $this->assertSame($bir->ozet, $iki->onceki_ozet);
        $this->assertTrue(app(ActivityChain::class)->dogrula()['saglam']);
    }

    public function test_degistirilen_satir_zinciri_kirar(): void
    {
        $kayit = app(ActivityRecorder::class);
        $kayit->kaydet('deneme', 'bir');
        $hedef = $kayit->kaydet('deneme', 'iki');
        $kayit->kaydet('deneme', 'üç');

        /* Doğrudan SQL: modelin olayları devreye girmesin, gerçek kurcalama böyle olur. */
        ActivityLog::query()->whereKey($hedef->getKey())->update(['aciklama' => 'başka bir şey']);

        $sonuc = app(ActivityChain::class)->dogrula();

        $this->assertFalse($sonuc['saglam']);
        $this->assertSame($hedef->getKey(), $sonuc['kirik_id']);
    }

    public function test_silinen_satir_zinciri_kirar(): void
    {
        $kayit = app(ActivityRecorder::class);
        $kayit->kaydet('deneme', 'bir');
        $silinecek = $kayit->kaydet('deneme', 'iki');
        $sonraki = $kayit->kaydet('deneme', 'üç');

        ActivityLog::query()->whereKey($silinecek->getKey())->delete();

        $sonuc = app(ActivityChain::class)->dogrula();

        $this->assertFalse($sonuc['saglam']);
        $this->assertSame($sonraki->getKey(), $sonuc['kirik_id']);
    }

    public function test_ozet_anahtar_olmadan_uretilemez(): void
    {
        $satir = app(ActivityRecorder::class)->kaydet('deneme', 'bir');

        /*
         * Anahtar değişirse özet tutmaz. Düz SHA kullanılsaydı veritabanına
         * yazabilen biri satırı değiştirip zinciri baştan hesaplayabilirdi.
         */
        config(['activity.chain_key' => 'baska-anahtar']);

        $this->assertFalse(app(ActivityChain::class)->dogrula()['saglam']);
        $this->assertNotNull($satir->ozet);
    }

    public function test_kullanici_silinince_kayit_kalir(): void
    {
        $kullanici = User::factory()->create();
        $satir = app(ActivityRecorder::class)->kaydet('deneme', 'bir', null, $kullanici);

        $ad = $kullanici->name;
        $kullanici->delete();

        /*
         * Denetim satırı TARİHSEL BİR KAYIT: hesap silinse bile duruyor,
         * kimliği ve o günkü adı yerinde ve zincir sağlam. Cascade silme
         * kaydı götürürdü; "NULL'a düşür" satırı değiştirip zinciri kırardı.
         */
        $this->assertDatabaseHas('activity_logs', ['id' => $satir->getKey()]);
        $this->assertSame($ad, $satir->fresh()->kullanici_adi);
        $this->assertTrue(app(ActivityChain::class)->dogrula()['saglam']);
    }

    public function test_kullanici_yetkisi_degisince_ne_degistigi_yazilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $hedef = User::factory()->create();

        $this->actingAs($yonetici)->post(route('admin.kullanicilar.update', $hedef), [
            'ad' => $hedef->name,
            'eposta' => $hedef->email,
            'rol' => 'teknik',
            'aktif' => '1',
            'istisna' => ['yedek:view' => 'izin'],
        ])->assertRedirect();

        $satir = ActivityLog::where('tur', 'kullanici_guncelle')->latest('id')->first();

        $this->assertNotNull($satir);
        $this->assertStringContainsString('yetkiler yok → Yedekler · Görüntüle', (string) $satir->aciklama);
    }
}
