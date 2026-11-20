<?php

use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->artisan('fleet:seed-history', ['--days' => 2]);
    $this->truck = Vehicle::where('name', 'CAM-07')->first();
    $this->day = CarbonImmutable::now('Africa/Casablanca')->subDay()->toDateString();
});

it('rejoue la journée d’un camion : tracé, synthèse et chronologie A → arrêts → B', function () {
    $r = $this->actingAs(User::where('email', 'operateur@demo.ma')->first())
        ->getJson("/api/history?vehicle_id={$this->truck->id}&date={$this->day}&start=00:00&end=23:59")->assertOk();

    if (! $r->json('data.points')) {
        $this->markTestSkipped('Jour de repos tiré au sort pour ce camion.');
    }
    $timeline = collect($r->json('data.timeline'));
    expect($timeline->first()['kind'])->toBe('departure')
        ->and($timeline->last()['kind'])->toBe('arrival')
        ->and($timeline->where('kind', 'stop')->pluck('n')->all())->toBe(range(1, $timeline->where('kind', 'stop')->count()))
        ->and($r->json('data.summary.distance_km'))->toBeGreaterThan(5)
        ->and($r->json('data.summary.departure.place'))->toBe('Dépôt Aïn Sebaâ');
    // Points [lat, lng, vitesse, cap, timestamp] triés dans le temps.
    $ts = array_column($r->json('data.points'), 4);
    expect($ts)->toBe(collect($ts)->sort()->values()->all());
});

it('accepte une période précise et la limite à 3 jours', function () {
    $this->actingAs(User::where('email', 'flotte@demo.ma')->first());
    $from = CarbonImmutable::parse($this->day.' 08:00', 'Africa/Casablanca');
    $this->getJson('/api/history?'.http_build_query(['vehicle_id' => $this->truck->id, 'from' => $from->toIso8601String(), 'to' => $from->addHours(2)->toIso8601String()]))->assertOk();
    $this->getJson('/api/history?'.http_build_query(['vehicle_id' => $this->truck->id, 'from' => $from->toIso8601String(), 'to' => $from->addDays(5)->toIso8601String()]))
        ->assertStatus(422)->assertJsonValidationErrors('to');
});

it('réserve l’export de la chronologie aux rôles autorisés', function () {
    $q = "vehicle_id={$this->truck->id}&date={$this->day}";
    $this->actingAs(User::where('email', 'operateur@demo.ma')->first())->get("/api/history/export?$q")->assertForbidden();
    $this->actingAs(User::where('email', 'flotte@demo.ma')->first())->get("/api/history/export?$q")->assertOk();
    $this->actingAs(User::where('email', 'chauffeur@demo.ma')->first())->getJson("/api/history?$q")->assertForbidden();
});
