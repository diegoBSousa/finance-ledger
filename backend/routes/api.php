<?php

use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Balances\GetAccountBalanceController;
use App\Http\Controllers\Balances\ListAccountBalancesController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\NoStoreResponse;
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
