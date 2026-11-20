<?php

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));
$as = fn (string $email) => test()->actingAs(User::where('email', $email)->firstOrFail());

it('génère les 6 rapports et leurs exports', function () use ($as) {
    $this->artisan('fleet:seed-history', ['--days' => 3]);
    $q = 'from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString().'&group=day';
    $as('flotte@demo.ma');
    foreach (['mileage', 'driving', 'customers', 'zones', 'drivers', 'vehicles'] as $type) {
        $this->getJson("/api/reports/$type?$q")->assertOk()->assertJsonStructure(['data' => ['title', 'summary', 'chart' => ['labels', 'datasets'], 'columns', 'rows']]);
    }
    expect($this->getJson("/api/reports/mileage?$q")->json('data.rows'))->not->toBeEmpty();
    $this->get("/api/reports/vehicles/export?$q&format=pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get("/api/reports/drivers/export?$q&format=xlsx")->assertOk();
    $as('operateur@demo.ma')->get("/api/reports/vehicles/export?$q&format=pdf")->assertForbidden();
    $this->getJson("/api/reports/inconnu?$q")->assertNotFound();
});

it('modifie les seuils (admin) et les applique', function () use ($as) {
    $as('admin@demo.ma')->putJson('/api/settings/thresholds', ['moving_kmh' => 8, 'offline_minutes' => 15, 'stop_minutes' => 3])->assertOk();
    expect(Setting::get('thresholds.moving_kmh'))->toBe(8)->and(Setting::get('thresholds.offline_minutes'))->toBe(15);
    $this->putJson('/api/settings/thresholds', ['moving_kmh' => 0, 'offline_minutes' => 1, 'stop_minutes' => 500])->assertStatus(422)
        ->assertJsonValidationErrors(['moving_kmh', 'offline_minutes', 'stop_minutes']);
    $as('flotte@demo.ma')->putJson('/api/settings/thresholds', ['moving_kmh' => 5, 'offline_minutes' => 10, 'stop_minutes' => 5])->assertForbidden();
});

it('démarre et met en pause le simulateur', function () use ($as) {
    $as('admin@demo.ma')->postJson('/api/settings/simulator', ['running' => false])->assertOk()->assertJsonPath('data.running', false);
    expect(Setting::get('simulator.running'))->toBeFalse();
    $this->getJson('/api/settings')->assertOk()->assertJsonPath('data.simulator.process_alive', false);
});

it('gère les utilisateurs selon les règles des rôles', function () use ($as) {
    $as('admin@demo.ma');
    $this->getJson('/api/users')->assertOk()->assertJsonCount(5, 'data');
    // Un administrateur ne crée pas de super administrateur…
    $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@demo.ma', 'role' => 'super_admin', 'password' => 'secret123'])->assertForbidden();
    // … ni ne modifie un super administrateur.
    $super = User::where('email', 'superadmin@demo.ma')->first();
    $this->putJson("/api/users/{$super->id}", ['name' => 'X', 'email' => $super->email, 'role' => 'admin'])->assertForbidden();
    // Un compte chauffeur doit être lié à une fiche chauffeur.
    $this->postJson('/api/users', ['name' => 'Mehdi', 'email' => 'mehdi@demo.ma', 'role' => 'driver', 'password' => 'secret123'])->assertStatus(422)->assertJsonValidationErrors('driver_id');
    $this->postJson('/api/users', ['name' => 'Ilyas Mansouri', 'email' => 'Ilyas@Demo.ma', 'role' => 'operator', 'password' => 'secret123'])->assertCreated()->assertJsonPath('data.email', 'ilyas@demo.ma');

    $op = User::where('email', 'operateur@demo.ma')->first();
    $op->forceFill(['locked_until' => now()->addMinutes(10)])->save();
    $this->postJson("/api/users/{$op->id}/unlock")->assertOk();
    expect($op->fresh()->isLocked())->toBeFalse();
    $as('flotte@demo.ma')->getJson('/api/users')->assertForbidden();
});
