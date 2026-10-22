<?php

use App\Http\Controllers\Api\DeviceApiController;
use App\Http\Controllers\Api\IdentityLogoutController;
use App\Http\Controllers\Api\IdentityRefreshController;
use App\Http\Controllers\Api\JunosApiController;
use Illuminate\Support\Facades\Route;

/*
 * BU PANELİN DIŞARIYA AÇIK TEK UCU.
 *
 * Yedekler API ile dağıtılmaz: dosyalar tarayıcıdan, oturum açmış ve yetkisi
 * olan kişiye verilir ve kimin indirdiği kayda geçer. Bir jetonun arka planda
 * bütün yapılandırmaları çekebilmesi, bu panelin varlık sebebine aykırı
 * olurdu.
 */

/*
 * OKUMA ARAYÜZÜ (v1). Kimlik yalnız jetondan (auth:api); oturum çerezi
 * kabul edilmez. Jeton sahibi adına konuşur ve onu AŞAMAZ (bkz.
 * TokenAbilityGate). Yazma ucu YOK: yedekleri cihazlar bırakıyor.
 * Belge config/api.php'den ve bu rotalardan üretilir.
 */
Route::middleware(['auth:api', 'throttle:api'])
    ->prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('devices', [DeviceApiController::class, 'index'])
            ->middleware('can:yedek:view')->name('devices.index');
        Route::get('devices/{device}/backups', [DeviceApiController::class, 'backups'])
            ->middleware('can:yedek:view')->name('devices.backups');
        Route::get('devices/{device}/backups/{file}', [DeviceApiController::class, 'download'])
            ->middleware('can:yedek:download')->name('devices.download');
        Route::get('junos', JunosApiController::class)
            ->middleware('can:junos:view')->name('junos.index');
    });

/*
 * MERKEZDEN GELEN "bu kimlik değişti" bildirimi.
 *
 * Merkez rol ya da durum değişince ANINDA haber veriyor; gecelik çekme turu
 * yedekte kalıyor (bildirim kaybolabilir: ağ, yeniden başlatma, panel o an
 * kapalı).
 *
 * Jeton gerektirmez: kimlik doğrulaması istek gövdesinin HMAC imzasıdır.
 * Gelen veriye GÜVENİLMEZ; panel doğruyu merkezden kendi sorar
 * (bkz. IdentityRefreshController).
 *
 * Hız sınırı var: imza tutmasa bile bu uç merkeze bir istek attırabilir.
 */
Route::post('identity/refresh', IdentityRefreshController::class)
    ->middleware('throttle:60,1')
    ->name('api.identity.refresh');

/* MERKEZDEN "bu kişi çıkış yaptı" — ortak çıkış (bkz. IdentityLogoutController). */
Route::post('identity/logout', IdentityLogoutController::class)
    ->middleware('throttle:60,1')
    ->name('api.identity.logout');
