<?php

use App\Konsol\Http\Controllers\AjanApiController;
use Illuminate\Support\Facades\Route;

/*
 * AJAN UÇLARI. Kimlik jetondan (auth:api); jetonun ve sahibinin konsol:ajan
 * yetkisi olmalı. Ajan iş sürerken saniyede bir çıktı aktarır: bu uçlar
 * genel API hız sınırına (throttle:api) girmez, yalnız kendi sınırına.
 */
Route::middleware(['api', 'auth:api', 'throttle:600,1', 'can:konsol:ajan'])
    ->prefix('api/v1/konsol')
    ->name('api.v1.konsol.')
    ->group(function (): void {
        Route::get('isler', [AjanApiController::class, 'isler'])->name('isler');
        Route::get('isler/{is}/yapilandirma', [AjanApiController::class, 'yapilandirma'])->name('yapilandirma');
        Route::post('isler/{is}/olay', [AjanApiController::class, 'olay'])->name('olay');
        Route::post('isler/{is}/cikti', [AjanApiController::class, 'cikti'])->name('cikti');
        Route::get('ajan/{platform}', [AjanApiController::class, 'indir'])
            ->whereIn('platform', ['linux', 'windows'])->name('ajan');
    });
