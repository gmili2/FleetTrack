<?php

/*
 * Matrice des droits : chaque route de l'API est appelée avec chacun des 5 rôles.
 * Une route autorisée ne doit jamais répondre 401/403 ; une route interdite doit répondre 403.
 * Les droits attendus viennent de la définition des rôles (cahier des charges) :
 *  - Super Administrateur / Administrateur : tout (sauf l'espace chauffeur) ;
 *  - Responsable de flotte : tout sauf utilisateurs et paramètres ;
 *  - Opérateur : lecture + missions, ni suppression ni export ;
 *  - Chauffeur : uniquement son espace « Mon service ».
 */

use App\Enums\MissionStatus;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\Geofence;
use App\Models\Mission;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Roles;
use Database\Seeders\DatabaseSeeder;

/** [méthode, uri, permission requise ou "driver_portal" / "auth"] — les suppressions en dernier. */
function apiRoutes(array $ids): array
{
    ['vehicle' => $v, 'driver' => $d, 'zone' => $z, 'mission' => $m, 'shift' => $s, 'user' => $u, 'own_shift' => $os] = $ids;
    $period = 'from='.now()->subDays(2)->toDateString().'&to='.now()->toDateString();

    return [
        ['GET', '/api/auth/me', 'auth'], ['GET', '/api/notifications', 'auth'], ['POST', '/api/notifications/read-all', 'auth'], ['POST', '/api/notifications/00000000-0000-0000-0000-000000000000/read', 'auth'],
        ['GET', '/api/dashboard', 'dashboard.view'],
        ['GET', '/api/live', 'tracking.view'], ['GET', '/api/live/geofences', 'tracking.view'], ['GET', "/api/live/$v", 'tracking.view'],
        ['GET', "/api/history?vehicle_id=$v", 'history.view'], ['GET', "/api/history/export?vehicle_id=$v", 'reports.export'],
        ['GET', '/api/vehicles', 'vehicles.view'], ['GET', "/api/vehicles/$v", 'vehicles.view'], ['GET', "/api/vehicles/$v/overview", 'vehicles.view'],
        ['GET', "/api/vehicles/$v/trips", 'vehicles.view'], ['GET', "/api/vehicles/$v/visits", 'vehicles.view'], ['GET', "/api/vehicles/$v/missions", 'vehicles.view'],
        ['GET', "/api/vehicles/$v/assignments", 'vehicles.view'], ['POST', '/api/vehicles', 'vehicles.create'], ['PUT', "/api/vehicles/$v", 'vehicles.update'],
        ['GET', '/api/vehicles/export', 'vehicles.export'],
        ['GET', '/api/drivers', 'drivers.view'], ['GET', "/api/drivers/$d", 'drivers.view'], ['GET', "/api/drivers/$d/shifts", 'drivers.view'],
        ['POST', '/api/drivers', 'drivers.create'], ['PUT', "/api/drivers/$d", 'drivers.update'], ['GET', '/api/drivers/export', 'drivers.export'],
        ['GET', '/api/shifts', 'shifts.view'], ['GET', '/api/badge-events', 'shifts.view'], ['GET', "/api/shifts/$s/track", 'shifts.view'], ['GET', '/api/shifts/export', 'shifts.export'],
        ['GET', '/api/geofences', 'geofences.view'], ['GET', "/api/geofences/$z", 'geofences.view'], ['GET', '/api/customers', 'geofences.view'],
        ['GET', "/api/geofences/$z/visits", 'geofences.view'], ['GET', "/api/geofences/$z/visits/export?format=xlsx", 'geofences.export'],
        ['POST', '/api/geofences', 'geofences.create'], ['PUT', "/api/geofences/$z", 'geofences.update'],
        ['GET', '/api/missions', 'missions.view'], ['GET', "/api/missions/$m", 'missions.view'], ['POST', '/api/missions', 'missions.create'],
        ['PUT', "/api/missions/$m", 'missions.update'], ['POST', "/api/missions/$m/comment", 'missions.operate'], ['GET', '/api/missions/export', 'missions.export'],
        ['GET', "/api/reports/mileage?$period", 'reports.view'], ['GET', "/api/reports/vehicles/export?$period&format=xlsx", 'reports.export'],
        ['GET', '/api/settings', 'settings.view'], ['GET', '/api/audit-logs', 'settings.view'], ['PUT', '/api/settings/thresholds', 'settings.update'],
        ['POST', '/api/settings/simulator', 'simulator.manage'],
        ['GET', '/api/users', 'users.view'], ['POST', '/api/users', 'users.create'], ['PUT', "/api/users/$u", 'users.update'], ['POST', "/api/users/$u/unlock", 'users.update'],
        ['GET', '/api/me/driver', 'driver_portal'], ['GET', '/api/me/driver/vehicles', 'driver_portal'], ['GET', "/api/me/driver/shifts/$os/track", 'driver_portal'],
        ['POST', '/api/me/driver/clock-in', 'driver_portal'], ['POST', '/api/me/driver/clock-out', 'driver_portal'],
        // Suppressions (en dernier)
        ['DELETE', "/api/missions/$m", 'missions.delete'], ['DELETE', "/api/geofences/$z", 'geofences.delete'],
        ['DELETE', "/api/drivers/$d", 'drivers.delete'], ['DELETE', "/api/vehicles/$v", 'vehicles.delete'], ['DELETE', "/api/users/$u", 'users.delete'],
    ];
}

it('applique les droits du rôle sur toutes les routes de l’API', function (string $email) {
    $this->seed(DatabaseSeeder::class);
    $actor = User::where('email', $email)->firstOrFail();
    $role = $actor->getRoleNames()->first();
    $granted = $role === Roles::SUPER_ADMIN ? Roles::allPermissions() : Roles::permissionsFor($role);

    $driverUser = User::where('email', 'chauffeur@demo.ma')->first();
    $ids = [
        'vehicle' => Vehicle::where('name', 'CAM-24')->value('id'),                       // sans mission en cours
        'driver' => Driver::where('employee_number', 'CH-1103')->value('id'),             // Driss Naciri, inactif
        'zone' => Geofence::where('name', 'Zone de livraison Port')->value('id'),
        'mission' => Mission::where('status', MissionStatus::Pending)->value('id'),
        'shift' => DriverShift::create(['driver_id' => Driver::first()->id, 'vehicle_id' => Vehicle::first()->id, 'start_at' => now()->subHours(2), 'end_at' => now()->subHour()])->id,
        'own_shift' => DriverShift::create(['driver_id' => $driverUser->driver_id, 'vehicle_id' => Vehicle::first()->id, 'start_at' => now()->subHours(5), 'end_at' => now()->subHours(4)])->id,
        'user' => User::where('email', 'operateur@demo.ma')->value('id'),
    ];
    $this->actingAs($actor);
    $failures = [];

    foreach (apiRoutes($ids) as [$method, $uri, $needs]) {
        $allowed = match ($needs) {
            'auth' => true,
            'driver_portal' => $role === Roles::DRIVER,
            default => in_array($needs, $granted, true),
        };
        // L'opérateur se modifie lui-même : cas particulier hors matrice.
        if ($actor->id === $ids['user'] && str_contains($uri, "/users/{$ids['user']}")) {
            continue;
        }
        $status = $this->json($method, $uri, [])->getStatusCode();
        $ok = $allowed ? ! in_array($status, [401, 403], true) : $status === 403;
        if (! $ok) {
            $failures[] = sprintf('%s %s → %d (attendu : %s, droit %s)', $method, $uri, $status, $allowed ? 'autorisé' : '403', $needs);
        }
    }

    expect($failures)->toBe([], "Rôle $role :\n".implode("\n", $failures));
})->with([
    'Super Administrateur' => 'superadmin@demo.ma',
    'Administrateur' => 'admin@demo.ma',
    'Responsable de flotte' => 'flotte@demo.ma',
    'Opérateur' => 'operateur@demo.ma',
    'Chauffeur' => 'chauffeur@demo.ma',
]);

it('refuse toute route protégée sans connexion', function () {
    $this->seed(DatabaseSeeder::class);
    $ids = ['vehicle' => 1, 'driver' => 1, 'zone' => 1, 'mission' => 1, 'shift' => 1, 'user' => 1, 'own_shift' => 1];
    foreach (apiRoutes($ids) as [$method, $uri]) {
        expect($this->json($method, $uri)->getStatusCode())->toBe(401, "$method $uri");
    }
});
