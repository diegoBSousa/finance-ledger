<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Operational liveness only. Business routes will require JWT authentication.
Route::get('/health', HealthController::class)->name('health');
