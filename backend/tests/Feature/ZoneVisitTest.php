<?php

use App\Models\Geofence;
use App\Models\GpsEvent;
use App\Models\Vehicle;
use App\Models\VehicleZoneVisit;
use App\Services\Tracking\PositionIngestor;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->zone = Geofence::where('name', 'Client — Atlas Béton')->firstOrFail(); // cercle 250 m, durée min. 300 s
    $this->vehicle = Vehicle::with('liveStatus', 'device')->where('name', 'CAM-07')->firstOrFail();
    $this->ingest = fn (float $lat, float $lng, CarbonImmutable $at, float $speed = 0) => app(PositionIngestor::class)
        ->ingest($this->vehicle, $lat, $lng, $speed, 0, $speed > 0, $at, broadcast: false);
    $this->t0 = CarbonImmutable::parse('2026-11-20 09:00:00');
});

it('ouvre une visite après la durée minimale et calcule sa durée à la sortie', function () {
    [$lat, $lng] = [$this->zone->center_lat, $this->zone->center_lng];
    ($this->ingest)($lat, $lng, $this->t0);
    ($this->ingest)($lat, $lng, $this->t0->addSeconds(200));
    expect(VehicleZoneVisit::where('geofence_id', $this->zone->id)->count())->toBe(0); // pas encore confirmée

    ($this->ingest)($lat, $lng, $this->t0->addSeconds(301));
    $visit = VehicleZoneVisit::where('geofence_id', $this->zone->id)->sole();
    expect($visit->entered_at->equalTo($this->t0))->toBeTrue()->and($visit->exited_at)->toBeNull();

    ($this->ingest)($lat + 0.01, $lng, $this->t0->addMinutes(42), 40); // ~1,1 km : sorti
    $visit->refresh();
    expect($visit->exited_at->equalTo($this->t0->addMinutes(42)))->toBeTrue()
        ->and($visit->duration_seconds)->toBe(42 * 60)
        ->and(GpsEvent::where('type', 'zone_enter')->where('geofence_id', $this->zone->id)->count())->toBe(1)
        ->and(GpsEvent::where('type', 'zone_exit')->where('geofence_id', $this->zone->id)->count())->toBe(1);
});

it('ignore un simple passage plus court que la durée minimale (anti-rebond)', function () {
    [$lat, $lng] = [$this->zone->center_lat, $this->zone->center_lng];
    ($this->ingest)($lat, $lng, $this->t0, 50);
    ($this->ingest)($lat, $lng, $this->t0->addSeconds(60), 50);
    ($this->ingest)($lat + 0.01, $lng, $this->t0->addSeconds(120), 50);
    ($this->ingest)($lat, $lng, $this->t0->addSeconds(180), 50);   // re-rentre : le compteur repart de zéro
    ($this->ingest)($lat + 0.01, $lng, $this->t0->addSeconds(400), 50);

    expect(VehicleZoneVisit::where('geofence_id', $this->zone->id)->count())->toBe(0);
});

it('ne ferme pas la visite sur un petit écart GPS au bord du cercle', function () {
    [$lat, $lng] = [$this->zone->center_lat, $this->zone->center_lng];
    ($this->ingest)($lat, $lng, $this->t0);
    ($this->ingest)($lat, $lng, $this->t0->addSeconds(310));
    // 260 m du centre : hors du rayon (250 m) mais dans la marge de sortie (25 m).
    ($this->ingest)($lat + 260 / 111_000, $lng, $this->t0->addSeconds(320));

    expect(VehicleZoneVisit::where('geofence_id', $this->zone->id)->whereNull('exited_at')->count())->toBe(1);
});

it('génère un historique cohérent et ses statistiques journalières', function () {
    $this->artisan('fleet:seed-history', ['--days' => 3])->assertSuccessful();
    expect(\App\Models\DailyVehicleStat::count())->toBeGreaterThan(0)
        ->and(\App\Models\DriverShift::whereNotNull('end_at')->count())->toBeGreaterThan(0)
        ->and(VehicleZoneVisit::whereNotNull('exited_at')->where('duration_seconds', '>', 0)->count())->toBeGreaterThan(0)
        ->and(\App\Models\DailyVehicleStat::max('distance_km'))->toBeGreaterThan(20);
});
