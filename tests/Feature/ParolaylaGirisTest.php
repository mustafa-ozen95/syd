<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PAROLAYLA GİRİŞ — merkez erişilemez olduğu günün kapısı.
 *
 * Asıl yol kimlik merkezi; bu form ekranda katlı durur. Kaldırılsaydı merkez
 * düştüğü gün panele kimse giremezdi, yöneticisi dahil — tam da her şeyin
 * bozuk olduğu an, switch yapılandırmalarına en çok ihtiyaç duyulan andır.
 */
class ParolaylaGirisTest extends TestCase
{
    use RefreshDatabase;

    private function kullanici(string $parola = 'cok-uzun-bir-parola'): User
    {
        return User::factory()->create([
            'email' => 'operator@ornek.test',
            'password' => Hash::make($parola),
            'aktif' => true,
        ]);
    }

    public function test_giris_ekraninda_parola_formu_durur(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Parolayla giriş')
            ->assertSee(route('login.attempt'), false);
    }

    public function test_dogru_parolayla_girilir(): void
    {
        $this->kullanici();

        $this->post(route('login.attempt'), [
            'email' => 'operator@ornek.test',
            'password' => 'cok-uzun-bir-parola',
        ])->assertRedirect(route('yedek.index'));

        $this->assertAuthenticated();
    }

    /**
     * Yanlış parola giriş ekranına döndürür ve sebebini ORADA söyler.
     *
     * Kardeş panellerdeki kalıp: hata doğrulama hatası olarak oturuma konur,
     * ekran onu `x-ui.flash` ile gösterir.
     */
    public function test_yanlis_parola_reddedilir(): void
    {
        $this->kullanici();

        $this->from(route('login'))->post(route('login.attempt'), [
            'email' => 'operator@ornek.test',
            'password' => 'yanlis',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Mesaj giriş ekranında GERÇEKTEN çizilir.
     *
     * Ayrı bir sınama, çünkü `assertSessionHasErrors` oturumdaki hatayı
     * TÜKETİYOR: aynı sınamada ardından gelen istek artık hiçbir hata
     * görmüyor ve ekranda mesajın çizildiği sanılıyordu.
     */
    public function test_hata_mesaji_giris_ekraninda_cizilir(): void
    {
        $this->kullanici();

        $this->from(route('login'))->post(route('login.attempt'), [
            'email' => 'operator@ornek.test',
            'password' => 'yanlis',
        ]);

        $this->get(route('login'))->assertSee('Bu bilgilerle giriş yapılamadı.');
    }

    /** PASİF HESAP parolası doğru olsa da giremez. */
    public function test_pasif_hesap_giremez(): void
    {
        $this->kullanici()->forceFill(['aktif' => false])->save();

        $this->from(route('login'))->post(route('login.attempt'), [
            'email' => 'operator@ornek.test',
            'password' => 'cok-uzun-bir-parola',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * BAŞARISIZ DENEME KAYDA GEÇER — ama parola yazılmaz.
     *
     * Kaydı okuyan herkes parolayı görürdü.
     */
    public function test_basarisiz_deneme_kayda_gecer_parola_yazilmaz(): void
    {
        $this->kullanici();

        $this->post(route('login.attempt'), [
            'email' => 'operator@ornek.test',
            'password' => 'gizli-deneme',
        ]);

        $satir = ActivityLog::query()->where('tur', 'giris_basarisiz')->first();

        $this->assertNotNull($satir);
        $this->assertStringContainsString('operator@ornek.test', (string) $satir->aciklama);
        $this->assertStringNotContainsString('gizli-deneme', (string) $satir->aciklama);
    }

    /** Yönetici, merkez düşerse girilebilsin diye parola verebilir. */
    public function test_yonetici_parola_verebilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $hedef = User::factory()->create(['email' => 'yeni@ornek.test']);

        $this->actingAs($yonetici)->post(route('admin.kullanicilar.update', $hedef), [
            'ad' => $hedef->name,
            'eposta' => $hedef->email,
            'rol' => 'teknik',
            'aktif' => '1',
            'yeni_sifre' => 'yeterince-uzun-parola',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('yeterince-uzun-parola', $hedef->fresh()->password));

        /* Parolanın KENDİSİ değil, değiştiği kayda geçer. */
        $satir = ActivityLog::query()->where('tur', 'kullanici_guncelle')->latest('id')->first();
        $this->assertStringContainsString('parola verildi', (string) $satir->aciklama);
        $this->assertStringNotContainsString('yeterince-uzun-parola', (string) $satir->aciklama);
    }

    /** Kısa parola kabul edilmez. */
    public function test_kisa_parola_reddedilir(): void
    {
        $yonetici = User::factory()->admin()->create();
        $hedef = User::factory()->create();

        $this->actingAs($yonetici)->post(route('admin.kullanicilar.update', $hedef), [
            'ad' => $hedef->name,
            'eposta' => $hedef->email,
            'rol' => 'teknik',
            'aktif' => '1',
            'yeni_sifre' => 'kisa',
        ])->assertSessionHasErrors('yeni_sifre');
    }
}
