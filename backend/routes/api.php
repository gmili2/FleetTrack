<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LiveController;
use App\Http\Controllers\Api\NotificationController;
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

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read']);

    // Suivi temps réel
    Route::middleware('can:tracking.view')->group(function () {
        Route::get('/live', [LiveController::class, 'index']);
        Route::get('/live/geofences', [LiveController::class, 'geofences']);
        Route::get('/live/{vehicle}', [LiveController::class, 'show']);
    });

    // Les autres modules métier (camions, chauffeurs, missions…) arrivent aux étapes suivantes,
    // chacun protégé par ses Policies et les permissions « module.action ».
});
