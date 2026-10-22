<?php

use App\ConsoleModule\Http\Controllers\AgentApiController;
use Illuminate\Support\Facades\Route;

/*
 * AJAN UÇLARI. Kimlik jetondan (auth:api); jetonun ve sahibinin konsol:ajan
 * yetkisi olmalı. Ajan iş sürerken saniyede bir çıktı aktarır: bu uçlar
 * genel API hız sınırına (throttle:api) girmez, yalnız kendi sınırına.
 */
Route::middleware(['api', 'auth:api', 'throttle:600,1', 'can:konsol:ajan'])
    ->prefix('api/v1/console')
    ->name('api.v1.console.')
    ->group(function (): void {
        Route::get('jobs', [AgentApiController::class, 'jobs'])->name('jobs');
        Route::get('jobs/{job}/configuration', [AgentApiController::class, 'configuration'])->name('configuration');
        Route::post('jobs/{job}/event', [AgentApiController::class, 'event'])->name('event');
        Route::post('jobs/{job}/output', [AgentApiController::class, 'output'])->name('output');
        Route::get('agent/{platform}', [AgentApiController::class, 'download'])
            ->whereIn('platform', ['linux', 'windows'])->name('agent');
    });
