<?php

use App\Models\Geofence;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleZoneVisit;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));
$as = fn (string $email) => test()->actingAs(User::where('email', $email)->firstOrFail());

it('liste les zones avec compteurs par type', function () use ($as) {
    $as('operateur@demo.ma')->getJson('/api/geofences')->assertOk()->assertJsonCount(8, 'data')
        ->assertJsonPath('meta.counts.customer', 4)->assertJsonPath('data.0.type', 'depot');
});

it('crée une zone polygone (centre calculé) et une zone cercle', function () use ($as) {
    $as('flotte@demo.ma')->postJson('/api/geofences', [
        'name' => 'Chantier Bouskoura', 'type' => 'site', 'shape' => 'polygon', 'color' => '#C2410C', 'min_visit_seconds' => 300,
        'coordinates' => [[33.45, -7.65], [33.45, -7.64], [33.46, -7.64], [33.46, -7.65]],
    ])->assertCreated()->assertJsonPath('data.center_lat', 33.455)->assertJsonPath('data.shape_label', 'Polygone');
    expect(Geofence::where('name', 'Chantier Bouskoura')->first()->contains(33.455, -7.645))->toBeTrue();

    $this->postJson('/api/geofences', [
        'name' => 'Client test', 'type' => 'customer', 'shape' => 'circle', 'center_lat' => 33.55, 'center_lng' => -7.6, 'radius_m' => 300,
        'color' => '#15803D', 'min_visit_seconds' => 120, 'customer_id' => \App\Models\Customer::first()->id,
    ])->assertCreated()->assertJsonPath('data.radius_m', 300);
});

it('valide la géométrie et le client d’une zone client', function () use ($as) {
    $as('flotte@demo.ma')->postJson('/api/geofences', ['name' => 'X', 'type' => 'customer', 'shape' => 'polygon', 'color' => 'rouge', 'min_visit_seconds' => 60, 'coordinates' => [[33.4, -7.6]]])
        ->assertStatus(422)->assertJsonValidationErrors(['coordinates', 'color', 'customer_id']);
    $this->postJson('/api/geofences', ['name' => 'Y', 'type' => 'site', 'shape' => 'circle', 'color' => '#000000', 'min_visit_seconds' => 60, 'radius_m' => 5])
        ->assertStatus(422)->assertJsonValidationErrors(['center_lat', 'radius_m']);
});

it('protège la gestion des zones selon le rôle', function () use ($as) {
    $zone = Geofence::first();
    $as('operateur@demo.ma')->putJson("/api/geofences/{$zone->id}", ['name' => 'Z'])->assertForbidden();
    $this->deleteJson("/api/geofences/{$zone->id}")->assertForbidden();
    $this->get("/api/geofences/{$zone->id}/visits/export?format=xlsx")->assertForbidden();
});

it('calcule les visites d’une zone et les exporte en PDF et Excel', function () use ($as) {
    $zone = Geofence::where('name', 'Client — Atlas Béton')->first();
    [$a, $b] = Vehicle::orderBy('id')->take(2)->get();
    VehicleZoneVisit::create(['vehicle_id' => $a->id, 'geofence_id' => $zone->id, 'entered_at' => now()->subHours(5), 'exited_at' => now()->subHours(4), 'duration_seconds' => 3600]);
    VehicleZoneVisit::create(['vehicle_id' => $a->id, 'geofence_id' => $zone->id, 'entered_at' => now()->subHours(3), 'exited_at' => now()->subHours(2)->subMinutes(30), 'duration_seconds' => 1800]);
    VehicleZoneVisit::create(['vehicle_id' => $b->id, 'geofence_id' => $zone->id, 'entered_at' => now()->subMinutes(20)]);

    $r = $as('flotte@demo.ma')->getJson("/api/geofences/{$zone->id}/visits")->assertOk();
    expect($r->json('stats.visits'))->toBe(3)->and($r->json('stats.ongoing'))->toBe(1)->and($r->json('stats.vehicles'))->toBe(2)
        ->and($r->json('stats.by_vehicle.0.seconds'))->toBe(5400)->and($r->json('data.0.ongoing'))->toBeTrue();

    $this->get("/api/geofences/{$zone->id}/visits/export?format=pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get("/api/geofences/{$zone->id}/visits/export?format=xlsx")->assertOk();
});

it('liste les pointages du jour avec compteurs et flux des badges', function () use ($as) {
    $v = Vehicle::where('name', 'CAM-07')->first();
    \App\Models\DriverShift::create(['driver_id' => $v->current_driver_id, 'vehicle_id' => $v->id, 'badge_number' => 'B-4410', 'start_at' => now()->subHour(), 'anomaly' => 'outside_depot']);
    \App\Models\DriverShift::create(['driver_id' => null, 'vehicle_id' => $v->id, 'badge_number' => 'B-9999', 'start_at' => now()->subHours(3), 'end_at' => now()->subHours(2), 'duration_seconds' => 3600, 'anomaly' => 'unknown_badge']);

    $as('operateur@demo.ma')->getJson('/api/shifts')->assertOk()
        ->assertJsonPath('meta.counts.all', 2)->assertJsonPath('meta.counts.open', 1)->assertJsonPath('meta.counts.anomalies', 2);
    $this->getJson('/api/shifts?tab=open')->assertJsonCount(1, 'data');
    $this->getJson('/api/badge-events')->assertOk();
    $this->get('/api/shifts/export')->assertForbidden();
    $as('flotte@demo.ma')->get('/api/shifts/export')->assertOk();
});
