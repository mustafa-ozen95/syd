<?php

use App\ConsoleModule\Http\Controllers\ConsoleController;
use App\ConsoleModule\Http\Controllers\PcController;
use Illuminate\Support\Facades\Route;

/* Konsol kurulum ekranı. Kapılar kaynak:aksiyon (bkz. ConsoleServiceProvider). */
Route::middleware(['web', 'auth'])->prefix('console')->name('console.')->group(function (): void {
    Route::get('/', [ConsoleController::class, 'index'])->middleware('can:konsol:view')->name('index');
    Route::post('/job', [ConsoleController::class, 'store'])->middleware('can:konsol:create')->name('store');
    Route::get('/backups', [ConsoleController::class, 'backups'])->middleware('can:konsol:create')->name('backups');
    Route::get('/job/{job}', [ConsoleController::class, 'show'])->middleware('can:konsol:view')->name('show');
    Route::get('/job/{job}/stream', [ConsoleController::class, 'stream'])->middleware('can:konsol:view')->name('stream');
    Route::get('/job/{job}/log', [ConsoleController::class, 'log'])->middleware('can:konsol:view')->name('log');
    Route::post('/job/{job}/input', [ConsoleController::class, 'input'])->middleware('can:konsol:create')->name('input');
    Route::put('/job/{job}/close', [ConsoleController::class, 'close'])->middleware('can:konsol:delete')->name('close');
    Route::delete('/job/{job}', [ConsoleController::class, 'destroy'])->middleware('can:konsol:delete')->name('destroy');

    Route::middleware('can:konsol:update')->group(function (): void {
        Route::post('/pc', [PcController::class, 'store'])->name('pc.store');
        Route::put('/pc/{pc}', [PcController::class, 'update'])->name('pc.update');
        Route::delete('/pc/{pc}', [PcController::class, 'destroy'])->name('pc.destroy');
        Route::post('/pc/{pc}/{action}', [PcController::class, 'action'])
            ->whereIn('action', ['test', 'install', 'status', 'uninstall'])->name('pc.islem');
    });
});
