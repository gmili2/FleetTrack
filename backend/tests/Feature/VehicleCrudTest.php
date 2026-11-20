<?php

use App\Models\Driver;
use App\Models\GpsDevice;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));
$as = fn (string $email) => test()->actingAs(User::where('email', $email)->firstOrFail());

function truckPayload(array $over = []): array
{
    return array_merge([
        'plate_number' => '77777-B-6', 'name' => 'cam-30', 'brand' => 'Volvo', 'model' => 'FH 500', 'type' => 'tractor',
        'year' => 2024, 'status' => 'active', 'speed_limit_kmh' => 90, 'imei' => '861234567890123', 'device_model' => 'Teltonika FMC130',
    ], $over);
}

it('liste les camions avec pagination, filtres et compteurs', function () use ($as) {
    $as('operateur@demo.ma')->getJson('/api/vehicles?per_page=10&sort=name')->assertOk()
        ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('meta.counts.maintenance', 1)
        ->assertJsonPath('data.0.name', 'CAM-01');
    $this->getJson('/api/vehicles?q=Benali')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'CAM-12');
    $this->getJson('/api/vehicles?type=tanker')->assertJsonCount(2, 'data');
});

it('crée un camion avec son boîtier GPS et son état en direct', function () use ($as) {
    $as('flotte@demo.ma')->postJson('/api/vehicles', truckPayload())->assertCreated()
        ->assertJsonPath('data.name', 'CAM-30')->assertJsonPath('data.device.imei', '861234567890123')->assertJsonPath('data.live.status', 'offline');
    expect(GpsDevice::where('imei', '861234567890123')->first()->vehicle->name)->toBe('CAM-30');
});

it('valide l’immatriculation, l’unicité et l’IMEI', function () use ($as) {
    $as('flotte@demo.ma')->postJson('/api/vehicles', truckPayload(['plate_number' => 'ABC', 'name' => 'CAM-07', 'imei' => '123']))
        ->assertStatus(422)->assertJsonValidationErrors(['plate_number', 'name', 'imei'])
        ->assertJsonPath('errors.plate_number.0', 'L’immatriculation doit être au format 12345-A-1.');
});

it('réaffecte un chauffeur et tient l’historique des affectations', function () use ($as) {
    $youssef = Driver::where('employee_number', 'CH-1012')->first(); // sur CAM-07
    $cam09 = Vehicle::where('name', 'CAM-09')->first();
    $as('admin@demo.ma')->putJson("/api/vehicles/{$cam09->id}", truckPayload([
        'plate_number' => $cam09->plate_number, 'name' => 'CAM-09', 'type' => 'tanker', 'current_driver_id' => $youssef->id, 'imei' => $cam09->device->imei,
    ]))->assertOk()->assertJsonPath('data.driver.name', 'Youssef Amrani');

    expect(Vehicle::where('name', 'CAM-07')->value('current_driver_id'))->toBeNull()
        ->and(VehicleAssignment::where('vehicle_id', $cam09->id)->whereNull('ended_at')->value('driver_id'))->toBe($youssef->id)
        ->and(VehicleAssignment::where('vehicle_id', $cam09->id)->whereNotNull('ended_at')->count())->toBe(1);
});

it('protège la création, la suppression et l’export selon le rôle', function () use ($as) {
    $cam = Vehicle::where('name', 'CAM-24')->first();
    $as('operateur@demo.ma')->postJson('/api/vehicles', truckPayload())->assertForbidden();
    $this->deleteJson("/api/vehicles/{$cam->id}")->assertForbidden();
    $this->get('/api/vehicles/export')->assertForbidden();

    $as('flotte@demo.ma')->get('/api/vehicles/export')->assertOk()->assertHeader('content-disposition');
    $this->deleteJson("/api/vehicles/{$cam->id}")->assertOk();
    expect(Vehicle::find($cam->id))->toBeNull()->and(Vehicle::withTrashed()->find($cam->id))->not->toBeNull();
});

it('refuse de supprimer un camion en mission', function () use ($as) {
    $cam = Vehicle::where('name', 'CAM-07')->first(); // mission en cours
    $as('admin@demo.ma')->deleteJson("/api/vehicles/{$cam->id}")->assertStatus(422);
});

it('renvoie la fiche camion et ses onglets', function () use ($as) {
    $cam = Vehicle::where('name', 'CAM-07')->first();
    $as('operateur@demo.ma');
    $this->getJson("/api/vehicles/{$cam->id}/overview")->assertOk()->assertJsonStructure(['data' => ['distance_km', 'driving_seconds', 'visits', 'missions', 'max_speed_kmh', 'daily']]);
    foreach (['trips', 'visits', 'missions', 'assignments'] as $tab) {
        $this->getJson("/api/vehicles/{$cam->id}/$tab")->assertOk();
    }
});
