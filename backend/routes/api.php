<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GeofenceController;
use App\Http\Controllers\Api\ShiftController;
use App\Http\Controllers\Api\ZoneVisitController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\MissionController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\DriverController;
use App\Http\Controllers\Api\DriverPortalController;
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

    // Espace chauffeur (rôle Chauffeur)
    Route::middleware('can:driver_portal.use')->prefix('me/driver')->group(function () {
        Route::get('/', [DriverPortalController::class, 'show']);
        Route::get('/vehicles', [DriverPortalController::class, 'vehicles']);
        Route::post('/clock-in', [DriverPortalController::class, 'clockIn'])->middleware('throttle:10,1');
        Route::post('/clock-out', [DriverPortalController::class, 'clockOut'])->middleware('throttle:10,1');
        Route::get('/shifts/{shift}/track', [DriverPortalController::class, 'track']);
    });

    Route::get('/dashboard', DashboardController::class)->middleware('can:dashboard.view');

    // Historique et GPS Replay (HistoryRequest vérifie history.view)
    Route::get('/history', [HistoryController::class, 'show']);
    Route::get('/history/export', [HistoryController::class, 'export']);

    // Camions (droits vérifiés par VehiclePolicy)
    Route::get('/vehicles/export', [VehicleController::class, 'export']);
    Route::apiResource('vehicles', VehicleController::class);
    Route::prefix('vehicles/{vehicle}')->group(function () {
        Route::get('/overview', [VehicleController::class, 'overview']);
        Route::get('/trips', [VehicleController::class, 'trips']);
        Route::get('/visits', [VehicleController::class, 'visits']);
        Route::get('/missions', [VehicleController::class, 'missions']);
        Route::get('/assignments', [VehicleController::class, 'assignments']);
    });

    // Chauffeurs (DriverPolicy) et historique de leurs services
    Route::get('/drivers/export', [DriverController::class, 'export']);
    Route::apiResource('drivers', DriverController::class);
    Route::get('/drivers/{driver}/shifts', [DriverController::class, 'shifts'])->middleware('can:drivers.view');
    Route::get('/shifts/{shift}/track', [DriverController::class, 'track'])->middleware('can:shifts.view');

    // Pointages
    Route::get('/shifts', [ShiftController::class, 'index']);
    Route::get('/shifts/export', [ShiftController::class, 'export']);
    Route::get('/badge-events', [ShiftController::class, 'badges']);

    // Zones (GeofencePolicy) et visites
    Route::get('/customers', [GeofenceController::class, 'customers']);
    Route::apiResource('geofences', GeofenceController::class);
    Route::get('/geofences/{geofence}/visits', [ZoneVisitController::class, 'index']);
    Route::get('/geofences/{geofence}/visits/export', [ZoneVisitController::class, 'export']);

    // Missions (MissionPolicy)
    Route::get('/missions/export', [MissionController::class, 'export']);
    Route::apiResource('missions', MissionController::class);
    Route::post('/missions/{mission}/{action}', [MissionController::class, 'transition'])->whereIn('action', ['start', 'complete', 'cancel', 'comment']);

    // Rapports
    Route::get('/reports/{type}', [ReportController::class, 'show']);
    Route::get('/reports/{type}/export', [ReportController::class, 'export']);

    // Paramètres, utilisateurs et rôles
    Route::get('/settings', [SettingsController::class, 'show']);
    Route::put('/settings/thresholds', [SettingsController::class, 'thresholds']);
    Route::post('/settings/simulator', [SettingsController::class, 'simulator']);
    Route::get('/audit-logs', [SettingsController::class, 'audit']);
    Route::apiResource('users', UserController::class)->except('show');
    Route::post('/users/{user}/unlock', [UserController::class, 'unlock']);
});
