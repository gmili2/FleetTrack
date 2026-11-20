<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

it('renvoie l’état de la flotte aux utilisateurs autorisés', function () {
    $this->actingAs(User::where('email', 'operateur@demo.ma')->first())
        ->getJson('/api/live')->assertOk()
        ->assertJsonCount(12, 'data')
        ->assertJsonPath('data.0.status', fn ($s) => in_array($s, ['moving', 'stopped', 'offline'], true));
});

it('renvoie le détail du jour d’un camion', function () {
    $this->actingAs(User::where('email', 'flotte@demo.ma')->first())
        ->getJson('/api/live/'.\App\Models\Vehicle::where('name', 'CAM-07')->value('id'))->assertOk()
        ->assertJsonStructure(['data' => ['name', 'driver', 'today' => ['distance_km', 'driving_seconds', 'stops_count'], 'events', 'trail']]);
});

it('refuse le suivi sans authentification', function () {
    $this->getJson('/api/live')->assertUnauthorized();
});
