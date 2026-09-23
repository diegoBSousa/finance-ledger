<?php

use App\Http\Controllers\Auth\CurrentUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\NoStoreAuthentication;
use Illuminate\Support\Facades\Route;

// Operational liveness only. Business routes will require JWT authentication.
Route::get('/health', HealthController::class)->name('health');

Route::prefix('auth')->name('auth.')->middleware(NoStoreAuthentication::class)->group(function (): void {
    Route::post('/login', LoginController::class)->middleware('throttle:login')->name('login');
    Route::middleware('jwt.auth')->group(function (): void {
        Route::get('/me', CurrentUserController::class)->name('me');
        Route::post('/logout', LogoutController::class)->name('logout');
    });
});
