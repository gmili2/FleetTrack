<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
| API REST JSON de FleetTrack. Authentification SPA par cookie (Sanctum) :
| le front appelle d'abord GET /sanctum/csrf-cookie puis POST /api/auth/login.
*/

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:20,1')
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    // Les modules métier (camions, chauffeurs, missions…) arrivent aux étapes suivantes,
    // chacun protégé par ses Policies et les permissions « module.action ».
});
