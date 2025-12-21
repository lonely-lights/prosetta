<?php

use Illuminate\Support\Facades\Route;
use LonelyLights\Prosetta\Http\Controllers\DashboardController;
use LonelyLights\Prosetta\Http\Controllers\FileController;
use LonelyLights\Prosetta\Http\Controllers\KeyController;
use LonelyLights\Prosetta\Http\Controllers\TranslationController;

/*
|--------------------------------------------------------------------------
| Prosetta Routes
|--------------------------------------------------------------------------
|
| These routes provide the admin UI for managing translations.
| They are prefixed with the configured route prefix (default: 'prosetta').
|
*/

Route::prefix(config('prosetta.routes.prefix', 'prosetta'))
    ->middleware(config('prosetta.routes.middleware', ['web', 'auth']))
    ->name('prosetta.')
    ->group(function () {
        // Dashboard
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Files
        Route::get('/files', [FileController::class, 'index'])->name('files.index');
        Route::get('/files/create', [FileController::class, 'create'])->name('files.create');
        Route::post('/files', [FileController::class, 'store'])->name('files.store');
        Route::get('/files/{file}', [FileController::class, 'show'])->name('files.show');
        Route::get('/files/{file}/edit', [FileController::class, 'edit'])->name('files.edit');
        Route::put('/files/{file}', [FileController::class, 'update'])->name('files.update');
        Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('files.destroy');

        // Keys
        Route::get('/keys/{key}', [KeyController::class, 'show'])->name('keys.show');
        Route::get('/keys/{key}/edit', [KeyController::class, 'edit'])->name('keys.edit');
        Route::put('/keys/{key}', [KeyController::class, 'update'])->name('keys.update');
        Route::delete('/keys/{key}', [KeyController::class, 'destroy'])->name('keys.destroy');

        // Translations (AJAX endpoints)
        Route::put('/translations/{translation}', [TranslationController::class, 'update'])->name('translations.update');
        Route::post('/translations/{translation}/approve', [TranslationController::class, 'approve'])->name('translations.approve');
        Route::post('/translations/{translation}/reject', [TranslationController::class, 'reject'])->name('translations.reject');

        // Actions
        Route::post('/sync', [DashboardController::class, 'sync'])->name('sync');
        Route::post('/export', [DashboardController::class, 'export'])->name('export');
    });
