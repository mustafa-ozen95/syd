<?php

use App\ConsoleModule\Http\Controllers\ConsoleController;
use App\ConsoleModule\Http\Controllers\PcController;
use Illuminate\Support\Facades\Route;

/* Konsol kurulum ekranı. Kapılar kaynak:aksiyon (bkz. ConsoleServiceProvider). */
Route::middleware(['web', 'auth'])->prefix('konsol')->name('konsol.')->group(function (): void {
    Route::get('/', [ConsoleController::class, 'index'])->middleware('can:konsol:view')->name('index');
    Route::post('/is', [ConsoleController::class, 'store'])->middleware('can:konsol:create')->name('store');
    Route::get('/yedekler', [ConsoleController::class, 'yedekler'])->middleware('can:konsol:create')->name('yedekler');
    Route::get('/is/{is}', [ConsoleController::class, 'show'])->middleware('can:konsol:view')->name('show');
    Route::get('/is/{is}/akis', [ConsoleController::class, 'akis'])->middleware('can:konsol:view')->name('akis');
    Route::get('/is/{is}/kayit', [ConsoleController::class, 'kayit'])->middleware('can:konsol:view')->name('kayit');
    Route::post('/is/{is}/girdi', [ConsoleController::class, 'girdi'])->middleware('can:konsol:create')->name('girdi');
    Route::put('/is/{is}/kapat', [ConsoleController::class, 'kapat'])->middleware('can:konsol:delete')->name('kapat');
    Route::delete('/is/{is}', [ConsoleController::class, 'destroy'])->middleware('can:konsol:delete')->name('destroy');

    Route::middleware('can:konsol:update')->group(function (): void {
        Route::post('/pc', [PcController::class, 'store'])->name('pc.store');
        Route::put('/pc/{pc}', [PcController::class, 'update'])->name('pc.update');
        Route::delete('/pc/{pc}', [PcController::class, 'destroy'])->name('pc.destroy');
        Route::post('/pc/{pc}/{islem}', [PcController::class, 'islem'])
            ->whereIn('islem', ['sina', 'kur', 'durum', 'kaldir'])->name('pc.islem');
    });
});
