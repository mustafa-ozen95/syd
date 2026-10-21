<?php

use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\AyarController;
use App\Http\Controllers\Admin\GuncellemeController;
use App\Http\Controllers\Admin\KayitController;
use App\Http\Controllers\Admin\KullaniciController;
use App\Http\Controllers\Admin\RolController;
use App\Http\Controllers\Admin\SertifikaController;
use App\Http\Controllers\Admin\VeritabaniController;
use App\Http\Controllers\Auth\MerkezGirisController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\JunosGoruntusuController;
use App\Http\Controllers\KurulumController;
use App\Http\Controllers\ProfilController;
use App\Http\Controllers\YedekController;
use Illuminate\Support\Facades\Route;

/*
 * Güvenlik kuralı: her rota ya `auth` altında olacak ya da neden olmadığı
 * yorumla açıklanacak. Aşağıdaki uçlar bilerek dışarıda: giriş yapmamış
 * kullanıcının onlara erişebilmesi gerekiyor.
 */

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
/* GET menü bağlantısı; POST ekran kilidinin çıkışı (bkz. oturum-kilidi.js). */
Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');

/*
 * PAROLAYLA GİRİŞ — merkez erişilemez olduğu günün kapısı.
 *
 * Asıl yol merkez; bu form ekranda KATLI durur. Kardeş panellerde de böyle:
 * kaldırılsaydı merkez düştüğü gün panele kimse giremezdi, yöneticisi dahil.
 *
 * Hız sınırı var: parola deneyerek bulmanın maliyeti olmalı.
 */
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1')
    ->name('login.attempt');

/*
 * KİMLİK MERKEZİ İLE GİRİŞ — ASIL YOL.
 *
 * Kullanıcı merkezin sayfasında giriş yapar, kimin girebileceğine merkez
 * karar verir ve hesap orada açılır. Kişiye özel hesap sayesinde kimin ne
 * indirdiği kayda geçer; paylaşılan tek bir parola bunu sağlayamazdı.
 */
Route::get('/giris/merkez', [MerkezGirisController::class, 'baslat'])->name('kimlik.giris');
Route::get('/giris/merkez/geri', [MerkezGirisController::class, 'geri'])->name('kimlik.donus');

Route::middleware('auth')->group(function () {
    Route::get('/yetkisiz', [AuthController::class, 'yetkisiz'])->name('yetkisiz');

    Route::get('/profilim', [ProfilController::class, 'show'])->name('profil.show');
    Route::post('/profilim/ad', [ProfilController::class, 'adGuncelle'])->name('profil.ad');
    Route::post('/profilim/yetki-talebi', [ProfilController::class, 'yetkiTalebiGonder'])->name('profil.yetki-talebi');

    /*
     * YEDEKLER. Panelin tamamı bu ekran: cihaz klasörleri, içlerindeki
     * yedek dosyaları ve indirme. Yazma ucu YOK — yedekleri switch'ler
     * FTP ile bırakıyor, panel yalnızca okur.
     *
     * Ana sayfa kapıya bağlı DEĞİL: yedek göremeyen kişi 403 yerine
     * görebildiği bölüme yönlenir (bkz. YedekController::index).
     */
    Route::get('/', [YedekController::class, 'index'])->name('yedek.index');
    Route::get('/klasor/{yol?}', [YedekController::class, 'klasor'])
        ->where('yol', '.*')
        ->middleware('can:yedek:view')
        ->name('yedek.klasor');
    Route::get('/indir/{yol}', [YedekController::class, 'indir'])
        ->where('yol', '.*')
        ->middleware('can:yedek:download')
        ->name('yedek.indir');

    /* Junos TFTP yerel kurulum komutları; liste panelden yönetilir. */
    Route::get('/kurulum', [KurulumController::class, 'index'])
        ->middleware('can:junos:view')
        ->name('kurulum.index');
    Route::post('/kurulum/goruntu', [JunosGoruntusuController::class, 'store'])
        ->middleware('can:junos:create')
        ->name('kurulum.goruntu.store');
    Route::put('/kurulum/goruntu/{goruntu}', [JunosGoruntusuController::class, 'update'])
        ->middleware('can:junos:update')
        ->name('kurulum.goruntu.update');
    Route::delete('/kurulum/goruntu/{goruntu}', [JunosGoruntusuController::class, 'destroy'])
        ->middleware('can:junos:delete')
        ->name('kurulum.goruntu.destroy');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/kullanicilar', [KullaniciController::class, 'index'])->name('kullanicilar.index');
        Route::post('/kullanicilar/{kullanici}/guncelle', [KullaniciController::class, 'update'])->name('kullanicilar.update');
        Route::post('/kullanicilar/{kullanici}/sil', [KullaniciController::class, 'destroy'])->name('kullanicilar.destroy');
        Route::post('/yetki-talepleri/{yetkiTalebi}/onayla', [KullaniciController::class, 'onaylaYetkiTalebi'])->name('yetki-talepleri.onayla');
        Route::post('/yetki-talepleri/{yetkiTalebi}/reddet', [KullaniciController::class, 'reddetYetkiTalebi'])->name('yetki-talepleri.reddet');

        /*
         * AYARLAR. Panelin davranışı koddan değil buradan değişir; değer
         * veritabanında durur ve config'in üzerine biner. Sırlar burada
         * DEĞİL kurulumun ortamında (bkz. config/settings.php).
         */
        /*
         * ROLLER ve API JETONLARI — Ayarlar şeridinin sekmeleri, yalnızca
         * yönetici (kardeş panellerdeki ekranların aynısı). Ayar bölümü rotasından
         * ÖNCE: "/ayarlar/{...}" onları yutmasın.
         */
        Route::middleware('can:admin')->group(function (): void {
            Route::get('/ayarlar/roller', [RolController::class, 'index'])->name('roller.index');
            Route::get('/ayarlar/roller/yeni', [RolController::class, 'create'])->name('roller.create');
            Route::post('/ayarlar/roller', [RolController::class, 'store'])->name('roller.store');
            Route::get('/ayarlar/roller/{rol}', [RolController::class, 'edit'])->name('roller.edit');
            Route::put('/ayarlar/roller/{rol}', [RolController::class, 'update'])->name('roller.update');
            Route::delete('/ayarlar/roller/{rol}', [RolController::class, 'destroy'])->name('roller.destroy');

            Route::get('/ayarlar/api', [ApiTokenController::class, 'index'])->name('api.index');
            Route::post('/ayarlar/api', [ApiTokenController::class, 'store'])->name('api.store');
            // Belge {apiToken}'dan ÖNCE: yoksa "belge" bir uuid sanılırdı.
            Route::get('/ayarlar/api/belge', [ApiTokenController::class, 'documentation'])->name('api.docs');
            Route::get('/ayarlar/api/{apiToken}', [ApiTokenController::class, 'show'])->name('api.show');
            Route::put('/ayarlar/api/{apiToken}', [ApiTokenController::class, 'update'])->name('api.update');
            Route::put('/ayarlar/api/{apiToken}/sir', [ApiTokenController::class, 'rotate'])->name('api.rotate');
            Route::put('/ayarlar/api/{apiToken}/iptal', [ApiTokenController::class, 'revoke'])->name('api.revoke');
            Route::delete('/ayarlar/api/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api.destroy');
        });

        Route::get('/ayarlar', [AyarController::class, 'index'])->name('ayarlar.index');
        Route::put('/ayarlar', [AyarController::class, 'update'])->name('ayarlar.update');

        /*
         * SERTİFİKA. Tur sunucuda koşar; panel isteği bırakır ve sonucu okur
         * (bkz. SertifikaController ve sertifika-ajan.sh).
         */
        Route::get('/sertifika', [SertifikaController::class, 'index'])->name('sertifika.index');
        Route::post('/sertifika/tur', [SertifikaController::class, 'baslat'])->name('sertifika.baslat');
        Route::post('/sertifika/onayla', [SertifikaController::class, 'tamamla'])->name('sertifika.tamamla');

        /*
         * VERİTABANI. Panel bağlantıyı sınar ve taşıma isteğini bırakır;
         * kopya yeniden başlatmada, panel kapalıyken yapılır
         * (bkz. VeritabaniController ve BaglantiDosyasi).
         */
        Route::get('/veritabani', [VeritabaniController::class, 'index'])->name('veritabani.index');
        Route::post('/veritabani/sina', [VeritabaniController::class, 'sina'])->name('veritabani.sina');
        Route::post('/veritabani/tasi', [VeritabaniController::class, 'tasi'])->name('veritabani.tasi');

        /*
         * GÜNCELLEME. Yalnızca bakar: çalışan sürüm GitHub'daki son başarılı
         * derlemeyle karşılaştırılır (bkz. GuncellemeDenetimi).
         */
        Route::get('/guncelleme', [GuncellemeController::class, 'index'])->name('guncelleme.index');
        Route::post('/guncelleme', [GuncellemeController::class, 'denetle'])->name('guncelleme.denetle');

        /*
         * KAYITLAR. Kayıt tutmak tek başına yetmiyor: kimse bakamıyorsa
         * kayıt yok sayılır. Denetim kaydı "kim ne yaptı"yı, hata kaydı
         * "ne patladı"yı söyler; ikisi ayrı sorudur, ayrı ekrandır.
         */
        Route::get('/kayitlar', [KayitController::class, 'denetim'])->name('kayitlar.denetim');
        Route::post('/kayitlar/dogrula', [KayitController::class, 'dogrula'])->name('kayitlar.dogrula');
        Route::get('/kayitlar/hatalar', [KayitController::class, 'hatalar'])->name('kayitlar.hatalar');
    });
});
