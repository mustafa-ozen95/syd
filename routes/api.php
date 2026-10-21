<?php

use App\Http\Controllers\Api\CihazApiController;
use App\Http\Controllers\Api\JunosApiController;
use App\Http\Controllers\Api\KimlikCikisController;
use App\Http\Controllers\Api\KimlikTazeleController;
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
        Route::get('cihazlar', [CihazApiController::class, 'index'])
            ->middleware('can:yedek:view')->name('cihazlar.index');
        Route::get('cihazlar/{cihaz}/yedekler', [CihazApiController::class, 'yedekler'])
            ->middleware('can:yedek:view')->name('cihazlar.yedekler');
        Route::get('cihazlar/{cihaz}/yedekler/{dosya}', [CihazApiController::class, 'indir'])
            ->middleware('can:yedek:download')->name('cihazlar.indir');
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
 * (bkz. KimlikTazeleController).
 *
 * Hız sınırı var: imza tutmasa bile bu uç merkeze bir istek attırabilir.
 */
Route::post('kimlik/tazele', KimlikTazeleController::class)
    ->middleware('throttle:60,1')
    ->name('api.kimlik.tazele');

/* MERKEZDEN "bu kişi çıkış yaptı" — ortak çıkış (bkz. KimlikCikisController). */
Route::post('kimlik/cikis', KimlikCikisController::class)
    ->middleware('throttle:60,1')
    ->name('api.kimlik.cikis');
