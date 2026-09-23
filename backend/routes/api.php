<?php

use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Balances\GetAccountBalanceController;
use App\Http\Controllers\Balances\ListAccountBalancesController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Imports\GetImportController;
use App\Http\Controllers\Imports\ListImportsController;
use App\Http\Controllers\Imports\UploadCsvController;
use App\Http\Middleware\NoStoreResponse;
use App\Http\Middleware\ParseImportUpload;
use Illuminate\Support\Facades\Route;

// Operational liveness only. Business routes require JWT authentication.
Route::get('/health', HealthController::class)->name('health');

Route::prefix('auth')->name('auth.')->middleware(NoStoreResponse::class)->group(function (): void {
    Route::post('/login', LoginController::class)->middleware('throttle:login')->name('login');
    Route::middleware('jwt.auth')->group(function (): void {
        Route::get('/me', CurrentUserController::class)->name('me');
        Route::post('/logout', LogoutController::class)->name('logout');
    });
});

Route::middleware([NoStoreResponse::class, 'jwt.auth'])->group(function (): void {
    Route::get('/balances', ListAccountBalancesController::class)->name('balances.index');
    Route::get('/accounts/{accountNumber}/balance', GetAccountBalanceController::class)
        ->where('accountNumber', '[1-9][0-9]*')->name('balances.show');
});

Route::middleware([NoStoreResponse::class, 'jwt.auth'])->group(function (): void {
    Route::post('/imports', UploadCsvController::class)
        ->middleware(ParseImportUpload::class)->name('imports.store');
    Route::get('/imports', ListImportsController::class)->name('imports.index');
    Route::get('/imports/{importId}', GetImportController::class)
        ->where('importId', '[1-9][0-9]*')->name('imports.show');
});
