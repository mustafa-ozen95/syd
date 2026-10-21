<?php

use App\ConsoleModule\Http\Controllers\AgentApiController;
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
        Route::get('isler', [AgentApiController::class, 'isler'])->name('isler');
        Route::get('isler/{is}/yapilandirma', [AgentApiController::class, 'yapilandirma'])->name('yapilandirma');
        Route::post('isler/{is}/olay', [AgentApiController::class, 'olay'])->name('olay');
        Route::post('isler/{is}/cikti', [AgentApiController::class, 'cikti'])->name('cikti');
        Route::get('ajan/{platform}', [AgentApiController::class, 'indir'])
            ->whereIn('platform', ['linux', 'windows'])->name('ajan');
    });
