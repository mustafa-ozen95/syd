<?php

use App\ConsoleModule\Http\Controllers\ConsoleController;
use App\ConsoleModule\Http\Controllers\PcController;
use Illuminate\Support\Facades\Route;

/* Konsol kurulum ekranı. Kapılar kaynak:aksiyon (bkz. ConsoleServiceProvider). */
Route::middleware(['web', 'auth'])->prefix('console')->name('console.')->group(function (): void {
    Route::get('/', [ConsoleController::class, 'index'])->middleware('can:konsol:view')->name('index');
    Route::get('/notifications', [ConsoleController::class, 'notifications'])->middleware('can:konsol:view')->name('notifications');
    Route::post('/job', [ConsoleController::class, 'store'])->middleware('can:konsol:create')->name('store');
    Route::get('/backups', [ConsoleController::class, 'backups'])->middleware('can:konsol:create')->name('backups');
    Route::match(['get', 'post'], '/backups/fields', [ConsoleController::class, 'fields'])->middleware('can:konsol:create')->name('fields');
    Route::post('/job/precheck', [ConsoleController::class, 'precheck'])->middleware('can:konsol:create')->name('precheck');
    Route::post('/job/ports', [ConsoleController::class, 'ports'])->middleware('can:konsol:create')->name('ports');
    Route::post('/job/preview', [ConsoleController::class, 'preview'])->middleware('can:konsol:create')->name('preview');
    Route::get('/job/{job}', [ConsoleController::class, 'show'])->middleware('can:konsol:view')->name('show');
    Route::get('/job/{job}/stream', [ConsoleController::class, 'stream'])->middleware('can:konsol:view')->name('stream');
    Route::get('/job/{job}/log', [ConsoleController::class, 'log'])->middleware('can:konsol:view')->name('log');
    Route::get('/job/{job}/final-config', [ConsoleController::class, 'finalConfig'])->middleware(['can:konsol:view', 'can:yedek:download'])->name('final-config');
    Route::post('/job/{job}/input', [ConsoleController::class, 'input'])->middleware('can:konsol:create')->name('input');
    Route::put('/job/{job}/close', [ConsoleController::class, 'close'])->middleware('can:konsol-is-kapat,job')->name('close');
    Route::delete('/job/{job}', [ConsoleController::class, 'destroy'])->middleware('can:konsol:delete')->name('destroy');

    Route::middleware('can:konsol:update')->group(function (): void {
        Route::post('/pc', [PcController::class, 'store'])->name('pc.store');
        Route::put('/pc/{pc}', [PcController::class, 'update'])->name('pc.update');
        Route::delete('/pc/{pc}', [PcController::class, 'destroy'])->name('pc.destroy');
        Route::post('/pc/{pc}/{action}', [PcController::class, 'action'])
            ->whereIn('action', ['test', 'install', 'status', 'uninstall'])->name('pc.islem');
    });
});
