<?php

use App\Enums\ShiftAnomaly;
use App\Models\BadgeEvent;
use App\Models\DriverShift;
use App\Models\Geofence;
use App\Models\Vehicle;
use App\Services\Tracking\PositionIngestor;
use App\Services\Tracking\ShiftService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->shifts = app(ShiftService::class);
    $this->shifts->notify = false;
    $this->depot = Geofence::where('type', 'depot')->firstOrFail();
    $this->usine = Geofence::where('type', 'factory')->firstOrFail();
    $this->vehicle = Vehicle::with('liveStatus', 'device', 'currentDriver')->where('name', 'CAM-07')->firstOrFail();
    $this->badge = $this->vehicle->currentDriver->badge_number; // B-4410
    $this->t0 = CarbonImmutable::parse('2026-11-20 07:52:00');
});

it('ouvre un service au badge d’entrée au dépôt et le ferme au badge de sortie', function () {
    $shift = $this->shifts->badgeIn($this->vehicle, $this->badge, $this->t0, $this->depot->center_lat, $this->depot->center_lng);
    expect($shift->anomaly)->toBeNull()
        ->and($shift->start_place)->toBe('Dépôt Aïn Sebaâ')
        ->and($shift->driver->employee_number)->toBe('CH-1012');

    // Quelques positions pour la distance : ~1,1 km aller-retour.
    $ingest = app(PositionIngestor::class);
    foreach ([[0, 0], [0.005, 5], [0, 10]] as [$dLat, $min]) {
        $ingest->ingest($this->vehicle, $this->depot->center_lat + $dLat, $this->depot->center_lng, 30, 0, true, $this->t0->addMinutes($min), broadcast: false);
    }

    $closed = $this->shifts->badgeOut($this->vehicle, $this->badge, $this->t0->addHours(8)->addMinutes(40), $this->depot->center_lat, $this->depot->center_lng);
    expect($closed->id)->toBe($shift->id)
        ->and($closed->duration_seconds)->toBe((8 * 60 + 40) * 60)
        ->and($closed->distance_km)->toBeGreaterThan(1.0)->toBeLessThan(1.3)
        ->and($closed->end_place)->toBe('Dépôt Aïn Sebaâ')
        ->and(BadgeEvent::where('driver_shift_id', $shift->id)->pluck('type')->map->value->all())->toBe(['in', 'out']);
});

it('signale une prise de service hors dépôt', function () {
    $shift = $this->shifts->badgeIn($this->vehicle, $this->badge, $this->t0, $this->usine->center_lat, $this->usine->center_lng);
    expect($shift->anomaly)->toBe(ShiftAnomaly::OutsideDepot)->and($shift->start_place)->toBe('Usine Mohammedia');
});

it('signale un badge inconnu', function () {
    $shift = $this->shifts->badgeIn($this->vehicle, 'B-9999', $this->t0, $this->depot->center_lat, $this->depot->center_lng);
    expect($shift->anomaly)->toBe(ShiftAnomaly::UnknownBadge)->and($shift->driver_id)->toBeNull();
});

it('signale une sortie non pointée puis clôture le service au badge suivant', function () {
    $first = $this->shifts->badgeIn($this->vehicle, $this->badge, $this->t0, $this->depot->center_lat, $this->depot->center_lng);
    // Retour au dépôt, moteur coupé depuis 20 min, sans badge de sortie.
    $this->vehicle->liveStatus->update([
        'geofence_id' => $this->depot->id, 'status' => 'stopped', 'ignition' => false, 'status_since' => $this->t0->addHours(6),
    ]);
    expect($this->shifts->flagUnbadgedExits($this->t0->addHours(6)->addMinutes(20)))->toBe(1)
        ->and($first->fresh()->anomaly)->toBe(ShiftAnomaly::ExitNotBadged)
        ->and($first->fresh()->end_at)->toBeNull();

    $second = $this->shifts->badgeIn($this->vehicle, $this->badge, $this->t0->addDay(), $this->depot->center_lat, $this->depot->center_lng);
    expect($first->fresh()->end_at)->not->toBeNull()
        ->and($second->anomaly)->toBeNull()
        ->and(DriverShift::whereNull('end_at')->where('vehicle_id', $this->vehicle->id)->count())->toBe(1);
});
