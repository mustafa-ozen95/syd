<?php

use App\Http\Controllers\Admin\ApiTokenController;
use App\Http\Controllers\Admin\CertificateController;
use App\Http\Controllers\Admin\DatabaseController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UpdateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\CenterLoginController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\JunosImageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SetupController;
use Illuminate\Support\Facades\Route;

/*
 * Güvenlik kuralı: her rota ya `auth` altında olacak ya da neden olmadığı
 * yorumla açıklanacak. Aşağıdaki uçlar bilerek dışarıda: giriş yapmamış
 * kullanıcının onlara erişebilmesi gerekiyor.
 */

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
/* GET menü bağlantısı; POST ekran kilidinin çıkışı (bkz. session-lock.js). */
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
Route::get('/login/center', [CenterLoginController::class, 'start'])->name('identity.login');
Route::get('/login/center/callback', [CenterLoginController::class, 'callback'])->name('identity.callback');

Route::middleware('auth')->group(function () {
    Route::get('/forbidden', [AuthController::class, 'forbidden'])->name('forbidden');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/profile/name', [ProfileController::class, 'updateName'])->name('profile.name');
    Route::post('/profile/permission-request', [ProfileController::class, 'submitPermissionRequest'])->name('profile.permission-request');

    /*
     * YEDEKLER. Panelin tamamı bu ekran: cihaz klasörleri, içlerindeki
     * yedek dosyaları ve indirme. Yazma ucu YOK — yedekleri switch'ler
     * FTP ile bırakıyor, panel yalnızca okur.
     *
     * Ana sayfa kapıya bağlı DEĞİL: yedek göremeyen kişi 403 yerine
     * görebildiği bölüme yönlenir (bkz. BackupController::index).
     */
    Route::get('/', [BackupController::class, 'index'])->name('backup.index');
    Route::get('/folder/{path?}', [BackupController::class, 'folder'])
        ->where('path', '.*')
        ->middleware('can:yedek:view')
        ->name('backup.folder');
    Route::get('/download/{path}', [BackupController::class, 'download'])
        ->where('path', '.*')
        ->middleware('can:yedek:download')
        ->name('backup.download');

    /* Junos TFTP yerel kurulum komutları; liste panelden yönetilir. */
    Route::get('/setup', [SetupController::class, 'index'])
        ->middleware('can:junos:view')
        ->name('setup.index');
    Route::post('/setup/image', [JunosImageController::class, 'store'])
        ->middleware('can:junos:create')
        ->name('setup.image.store');
    Route::put('/setup/image/{image}', [JunosImageController::class, 'update'])
        ->middleware('can:junos:update')
        ->name('setup.image.update');
    Route::delete('/setup/image/{image}', [JunosImageController::class, 'destroy'])
        ->middleware('can:junos:delete')
        ->name('setup.image.destroy');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users/{user}/update', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/delete', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/permission-requests/{permissionRequest}/approve', [UserController::class, 'approvePermissionRequest'])->name('permission-requests.approve');
        Route::post('/permission-requests/{permissionRequest}/reject', [UserController::class, 'rejectPermissionRequest'])->name('permission-requests.reject');

        /*
         * AYARLAR. Panelin davranışı koddan değil buradan değişir; değer
         * veritabanında durur ve config'in üzerine biner. Sırlar burada
         * DEĞİL kurulumun ortamında (bkz. config/settings.php).
         */
        /*
         * ROLLER ve API JETONLARI — Ayarlar şeridinin sekmeleri, yalnızca
         * yönetici (kardeş panellerdeki ekranların aynısı). Ayar bölümü rotasından
         * ÖNCE: "/settings/{...}" onları yutmasın.
         */
        Route::middleware('can:admin')->group(function (): void {
            Route::get('/settings/roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('/settings/roles/new', [RoleController::class, 'create'])->name('roles.create');
            Route::post('/settings/roles', [RoleController::class, 'store'])->name('roles.store');
            Route::get('/settings/roles/{role}', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('/settings/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('/settings/roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

            Route::get('/settings/api', [ApiTokenController::class, 'index'])->name('api.index');
            Route::post('/settings/api', [ApiTokenController::class, 'store'])->name('api.store');
            // Belge {apiToken}'dan ÖNCE: yoksa "belge" bir uuid sanılırdı.
            Route::get('/settings/api/docs', [ApiTokenController::class, 'documentation'])->name('api.docs');
            Route::get('/settings/api/{apiToken}', [ApiTokenController::class, 'show'])->name('api.show');
            Route::put('/settings/api/{apiToken}', [ApiTokenController::class, 'update'])->name('api.update');
            Route::put('/settings/api/{apiToken}/secret', [ApiTokenController::class, 'rotate'])->name('api.rotate');
            Route::put('/settings/api/{apiToken}/revoke', [ApiTokenController::class, 'revoke'])->name('api.revoke');
            Route::delete('/settings/api/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api.destroy');
        });

        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');

        /*
         * SERTİFİKA. Tur sunucuda koşar; panel isteği bırakır ve sonucu okur
         * (bkz. CertificateController ve certificate-agent.sh).
         */
        Route::get('/certificate', [CertificateController::class, 'index'])->name('certificate.index');
        Route::post('/certificate/run', [CertificateController::class, 'start'])->name('certificate.start');
        Route::post('/certificate/confirm', [CertificateController::class, 'complete'])->name('certificate.complete');

        /*
         * VERİTABANI. Panel bağlantıyı sınar ve taşıma isteğini bırakır;
         * kopya yeniden başlatmada, panel kapalıyken yapılır
         * (bkz. DatabaseController ve ConnectionFile).
         */
        Route::get('/database', [DatabaseController::class, 'index'])->name('database.index');
        Route::post('/database/test', [DatabaseController::class, 'test'])->name('database.test');
        Route::post('/database/migrate', [DatabaseController::class, 'migrate'])->name('database.migrate');

        /*
         * GÜNCELLEME. Çalışan sürüm GitHub'daki son başarılı derlemeyle
         * karşılaştırılır (bkz. UpdateCheck); yeni sürüm varsa apply platformun
         * deploy hook'unu tetikler (bkz. UpdateApply).
         */
        Route::get('/update', [UpdateController::class, 'index'])->name('update.index');
        Route::post('/update', [UpdateController::class, 'check'])->name('update.check');
        Route::post('/update/apply', [UpdateController::class, 'apply'])->name('update.apply');

        /*
         * KAYITLAR. Kayıt tutmak tek başına yetmiyor: kimse bakamıyorsa
         * kayıt yok sayılır. Denetim kaydı "kim ne yaptı"yı, hata kaydı
         * "ne patladı"yı söyler; ikisi ayrı sorudur, ayrı ekrandır.
         */
        Route::get('/logs', [LogController::class, 'audit'])->name('logs.audit');
        Route::post('/logs/verify', [LogController::class, 'verify'])->name('logs.verify');
        Route::get('/logs/errors', [LogController::class, 'errors'])->name('logs.errors');
    });
});
