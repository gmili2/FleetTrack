<?php

use App\Enums\ShiftAnomaly;
use App\Models\DriverShift;
use App\Models\Geofence;
use App\Models\GpsDevice;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->driverUser = User::where('email', 'chauffeur@demo.ma')->firstOrFail();
    $this->truck = Vehicle::where('name', 'CAM-09')->firstOrFail(); // garé au dépôt
});

/** Pointage avec la carte installée sur le camion (ou la carte donnée). */
function clockIn($test, Vehicle $v, ?int $deviceId = null)
{
    return $test->postJson('/api/me/driver/clock-in', ['vehicle_id' => $v->id, 'gps_device_id' => $deviceId ?? GpsDevice::where('vehicle_id', $v->id)->value('id')]);
}

it('permet au chauffeur de pointer l’entrée sur un camion puis la sortie', function () {
    $this->actingAs($this->driverUser);

    clockIn($this, $this->truck)
        ->assertCreated()->assertJsonPath('data.vehicle.name', 'CAM-09')->assertJsonPath('data.source', 'app')->assertJsonPath('data.device.label', 'GPS-05');

    $shift = DriverShift::where('driver_id', $this->driverUser->driver_id)->sole();
    expect($shift->start_place)->toBe('Dépôt Aïn Sebaâ')->and($shift->anomaly)->toBeNull();

    // Le suivi en direct affiche désormais ce chauffeur sur CAM-09.
    $this->actingAs(User::where('email', 'flotte@demo.ma')->first())
        ->getJson('/api/live')->assertOk()
        ->assertJsonFragment(['name' => 'Jamal Ouazzani', 'on_shift' => true]);

    $this->actingAs($this->driverUser)->postJson('/api/me/driver/clock-out')->assertOk()->assertJsonPath('data.open', false);
    expect($shift->fresh()->end_at)->not->toBeNull();
});

it('relève le chauffeur badgé précédent et signale une prise hors dépôt', function () {
    $usine = Geofence::where('type', 'factory')->first();
    $this->truck->liveStatus->update(['latitude' => $usine->center_lat, 'longitude' => $usine->center_lng]);
    $prev = DriverShift::create(['driver_id' => $this->truck->current_driver_id, 'vehicle_id' => $this->truck->id, 'badge_number' => 'B-4422', 'start_at' => now()->subHours(3)]);

    clockIn($this->actingAs($this->driverUser), $this->truck)->assertCreated();

    expect($prev->fresh()->end_at)->not->toBeNull()
        ->and($prev->fresh()->anomaly)->toBeNull()
        ->and(DriverShift::where('driver_id', $this->driverUser->driver_id)->sole()->anomaly)->toBe(ShiftAnomaly::OutsideDepot);
});

it('refuse un camion en route ou déjà pris, et un double pointage', function () {
    $this->actingAs($this->driverUser);
    $moving = Vehicle::where('name', 'CAM-07')->first();
    $moving->liveStatus->update(['status' => 'moving', 'speed_kmh' => 60]);
    clockIn($this, $moving)->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

    clockIn($this, $this->truck)->assertCreated();
    clockIn($this, Vehicle::where('name', 'CAM-05')->first())
        ->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
});

it('réserve l’espace chauffeur au rôle Chauffeur', function () {
    $this->actingAs($this->driverUser)->getJson('/api/live')->assertForbidden();
    $this->actingAs($this->driverUser)->getJson('/api/drivers')->assertForbidden();
    $this->actingAs(User::where('email', 'admin@demo.ma')->first())->getJson('/api/me/driver')->assertForbidden();
});

it('montre l’historique des services d’un chauffeur au back-office', function () {
    clockIn($this->actingAs($this->driverUser), $this->truck);
    $shiftId = DriverShift::where('driver_id', $this->driverUser->driver_id)->value('id');

    $this->actingAs(User::where('email', 'operateur@demo.ma')->first())
        ->getJson("/api/drivers/{$this->driverUser->driver_id}/shifts")->assertOk()
        ->assertJsonPath('data.0.vehicle.name', 'CAM-09')->assertJsonPath('data.0.open', true);
    $this->getJson("/api/shifts/$shiftId/track")->assertOk()->assertJsonStructure(['data' => ['shift', 'summary', 'points']]);
});

it('installe une carte libre sur le camion choisi et refuse la carte d’un autre camion', function () {
    $this->actingAs($this->driverUser);
    $old = GpsDevice::where('vehicle_id', $this->truck->id)->first();
    $spare = GpsDevice::where('label', 'GPS-13')->first();
    $other = GpsDevice::where('label', 'GPS-01')->first(); // installée sur CAM-07

    $this->getJson('/api/me/driver/devices')->assertOk()->assertJsonFragment(['name' => 'GPS-13', 'vehicle_id' => null])->assertJsonMissing(['name' => 'GPS-15']);

    clockIn($this, $this->truck, $other->id)->assertStatus(422)->assertJsonValidationErrors('gps_device_id');
    clockIn($this, $this->truck, GpsDevice::where('label', 'GPS-15')->value('id'))->assertStatus(422)->assertJsonValidationErrors('gps_device_id'); // désactivée
    clockIn($this, $this->truck, $spare->id)->assertCreated()->assertJsonPath('data.device.label', 'GPS-13');

    expect($spare->fresh()->vehicle_id)->toBe($this->truck->id)
        ->and($old->fresh()->vehicle_id)->toBeNull()
        ->and(DriverShift::where('driver_id', $this->driverUser->driver_id)->sole()->gps_device_id)->toBe($spare->id);
    $this->getJson('/api/me/driver/devices')->assertJsonMissing(['name' => 'GPS-13']); // en service
});

it('exige une carte GPS au pointage', function () {
    $this->actingAs($this->driverUser)->postJson('/api/me/driver/clock-in', ['vehicle_id' => $this->truck->id])
        ->assertStatus(422)->assertJsonValidationErrors('gps_device_id');
});
