<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Veritabani\BaglantiDosyasi;
use App\Services\Veritabani\UzakVeritabani;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Veritabanı sekmesi: sına, sonra taşı.
 *
 * Asıl kurallar: sınanmamış bir hedefe taşınmaz, taşı düğmesi sınamaları
 * yeniden yapar, parola ne sayfada ne denetim kaydında görünür. Kopyanın
 * kendisi başlatıcıda yapılır; burada yalnızca isteğin doğru bırakıldığı
 * sınanır.
 */
class VeritabaniEkraniTest extends TestCase
{
    use RefreshDatabase;

    private const HEDEF = [
        'host' => 'db.ornek.local',
        'port' => 3306,
        'database' => 'syd_uzak',
        'username' => 'syd',
        'password' => 'Gizli-Parola-123',
    ];

    private string $dizin;

    private BaglantiDosyasi $dosya;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dizin = sys_get_temp_dir().'/veritabani-'.bin2hex(random_bytes(4));
        $this->dosya = new BaglantiDosyasi(app('encrypter'), $this->dizin);
        $this->instance(BaglantiDosyasi::class, $this->dosya);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dizin.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dizin);

        parent::tearDown();
    }

    /**
     * Ağa çıkmayan sahte: sınamaların sonucu sırayla verilir.
     *
     * @param  list<bool>  $sirayla  her sina() çağrısında hepsi geçiyor mu
     */
    private function sahte(array $sirayla = [true], string $kaynak = 'ortam'): UzakVeritabani
    {
        $sahte = new class($this->dosya, $sirayla, $kaynak) extends UzakVeritabani
        {
            public int $sinama = 0;

            public function __construct(BaglantiDosyasi $dosya, private array $sirayla, private string $kaynak)
            {
                parent::__construct($dosya);
            }

            public function bilgi(): array
            {
                return ['kaynak' => $this->kaynak, 'host' => 'db', 'port' => '3306', 'database' => 'syd',
                    'username' => 'syd', 'surum' => '10.11.14-MariaDB', 'boyut' => 2_500_000, 'tablo' => 48, 'ozel' => 0];
            }

            public function sina(array $hedef): array
            {
                $gecti = $this->sirayla[min($this->sinama++, count($this->sirayla) - 1)];

                return [
                    ['ad' => 'Bağlantı', 'tamam' => true, 'ayrinti' => $hedef['username'].'@'.$hedef['host']],
                    ['ad' => 'Hedef veritabanı boş', 'tamam' => $gecti, 'ayrinti' => $gecti ? 'Tablo yok.' : '3 tablo var; dolu bir veritabanının üzerine yazılmaz.'],
                ];
            }
        };

        $this->instance(UzakVeritabani::class, $sahte);

        return $sahte;
    }

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_ekran_yalnizca_yoneticide_acilir(): void
    {
        $this->sahte();

        $this->actingAs(User::factory()->rol('muhasebe')->create())
            ->get(route('admin.veritabani.index'))
            ->assertRedirect(route('yetkisiz'));

        $this->actingAs($this->yonetici())
            ->get(route('admin.veritabani.index'))
            ->assertOk()
            ->assertSee('Şu anki veritabanı')
            ->assertSee('Kurulum ayarı')
            ->assertSee('10.11.14-MariaDB')
            ->assertSee('Bağlantıyı sına');
    }

    public function test_sinama_gecerse_tasi_dugmesi_gorunur_parola_gorunmez(): void
    {
        $this->sahte([true]);

        $this->actingAs($this->yonetici())
            ->from(route('admin.veritabani.index'))
            ->followingRedirects()
            ->post(route('admin.veritabani.sina'), self::HEDEF)
            ->assertOk()
            ->assertSee('Sınamalar geçti')
            ->assertSee('syd@db.ornek.local:3306/syd_uzak hedefine taşı')
            ->assertDontSee('Gizli-Parola-123');
    }

    public function test_sinama_gecmezse_sebep_gorunur_tasi_dugmesi_gorunmez(): void
    {
        $this->sahte([false]);

        $this->actingAs($this->yonetici())
            ->from(route('admin.veritabani.index'))
            ->followingRedirects()
            ->post(route('admin.veritabani.sina'), self::HEDEF)
            ->assertOk()
            ->assertSee('3 tablo var')
            ->assertSee('geçmedi')
            ->assertDontSee('hedefine taşı');
    }

    public function test_konteynerin_kendi_adresi_reddedilir(): void
    {
        $sahte = $this->sahte();

        $this->actingAs($this->yonetici())
            ->from(route('admin.veritabani.index'))
            ->post(route('admin.veritabani.sina'), [...self::HEDEF, 'host' => 'localhost'])
            ->assertSessionHasErrors('host');

        $this->assertSame(0, $sahte->sinama, 'Sınama koşmamalıydı.');
    }

    public function test_sinanmadan_tasinmaz(): void
    {
        $this->sahte();

        $this->actingAs($this->yonetici())
            ->from(route('admin.veritabani.index'))
            ->post(route('admin.veritabani.tasi'))
            ->assertSessionHasErrors('veritabani');

        $this->assertNull($this->dosya->durum());
        $this->assertNull($this->dosya->baglanti());
    }

    public function test_tasi_istegi_birakir_ve_yeniden_baslatma_ister(): void
    {
        $this->sahte([true]);
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)
            ->from(route('admin.veritabani.index'))
            ->post(route('admin.veritabani.sina'), self::HEDEF);

        $this->actingAs($yonetici)
            ->from(route('admin.veritabani.index'))
            ->post(route('admin.veritabani.tasi'), ['reason' => 'uzak sunucuya geçiş'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(self::HEDEF, $this->dosya->baglanti());
        $this->assertSame('tasinacak', $this->dosya->asama());
        $this->assertSame('syd@db.ornek.local:3306/syd_uzak', $this->dosya->durum()['hedef']);
        $this->assertSame($yonetici->name, $this->dosya->durum()['isteyen']);
        $this->assertFileExists($this->dizin.'/yeniden-baslat');

        $kayit = ActivityLog::query()->where('tur', 'veritabani_tasi')->sole();
        $this->assertStringContainsString('syd@db.ornek.local:3306/syd_uzak', $kayit->aciklama);
        $this->assertStringContainsString('uzak sunucuya geçiş', $kayit->aciklama);
        $this->assertStringNotContainsString('Gizli-Parola-123', $kayit->aciklama);

        $this->actingAs($yonetici)
            ->get(route('admin.veritabani.index'))
            ->assertSee('istendi, yeniden başlatma bekleniyor')
            ->assertDontSee('Bağlantıyı sına');
    }

    /** Sınama ile taşı arasında hedef dolmuş olabilir: taşı sınamaları baştan yapar. */
    public function test_tasi_sinamalari_yeniden_yapar(): void
    {
        $sahte = $this->sahte([true, false]);
        $yonetici = $this->yonetici();

        $this->actingAs($yonetici)->from(route('admin.veritabani.index'))
            ->post(route('admin.veritabani.sina'), self::HEDEF);

        $this->actingAs($yonetici)->from(route('admin.veritabani.index'))
            ->post(route('admin.veritabani.tasi'))
            ->assertSessionHasErrors('veritabani');

        $this->assertSame(2, $sahte->sinama);
        $this->assertNull($this->dosya->durum());
        $this->assertNull($this->dosya->baglanti());
        $this->assertFileDoesNotExist($this->dizin.'/yeniden-baslat');
        $this->assertSame(0, ActivityLog::query()->where('tur', 'veritabani_tasi')->count());
    }

    public function test_tamamlanmis_tasimadan_sonra_form_yok_sonuc_gorunur(): void
    {
        $this->sahte(kaynak: 'panel');
        $this->dosya->asamaYaz('tasinacak', null, ['hedef' => 'syd@db.ornek.local:3306/syd_uzak']);
        $this->dosya->asamaYaz('deneniyor', '48 tablo, 1200 satır kopyalandı; iki tarafta sayılar aynı.');
        $this->dosya->asamaYaz('etkin');

        $this->actingAs($this->yonetici())
            ->get(route('admin.veritabani.index'))
            ->assertOk()
            ->assertSee('Uzak (panel ayarı)')
            ->assertSee('tamamlandı')
            ->assertSee('48 tablo, 1200 satır kopyalandı')
            ->assertDontSee('Bağlantıyı sına');
    }

    public function test_basarisiz_tasimanin_sebebi_gorunur_ve_yeniden_denenebilir(): void
    {
        $this->sahte();
        $this->dosya->asamaYaz('tasinacak', null, ['hedef' => 'syd@db.ornek.local:3306/syd_uzak']);
        $this->dosya->asamaYaz('basarisiz', 'Uzak veritabanı boş değil (3 tablo); üzerine yazılmaz.');

        $this->actingAs($this->yonetici())
            ->get(route('admin.veritabani.index'))
            ->assertOk()
            ->assertSee('yapılmadı')
            ->assertSee('Uzak veritabanı boş değil (3 tablo)')
            ->assertSee('Bağlantıyı sına');
    }

    public function test_sekme_ayarlar_seridinde_gorunur(): void
    {
        $this->sahte();

        $this->actingAs($this->yonetici())
            ->get(route('admin.sertifika.index'))
            ->assertSee(route('admin.veritabani.index'));
    }
}
