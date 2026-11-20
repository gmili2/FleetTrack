<?php

use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));
$as = fn (string $email) => test()->actingAs(User::where('email', $email)->firstOrFail());

$payload = fn (array $over = []) => array_merge([
    'employee_number' => 'ch-2001', 'badge_number' => 'b-7001', 'first_name' => 'Yassine', 'last_name' => 'Berrada', 'phone' => '+212 6 11 22 33 44',
    'license_number' => 'C-123456', 'license_category' => 'CE', 'license_expires_at' => now()->addYears(3)->toDateString(), 'status' => 'available',
], $over);

it('crée, modifie et supprime un chauffeur', function () use ($as, $payload) {
    $id = $as('flotte@demo.ma')->postJson('/api/drivers', $payload())->assertCreated()
        ->assertJsonPath('data.employee_number', 'CH-2001')->assertJsonPath('data.badge_number', 'B-7001')->json('data.id');
    $this->putJson("/api/drivers/$id", $payload(['status' => 'rest']))->assertOk()->assertJsonPath('data.status_label', 'Repos');
    $this->deleteJson("/api/drivers/$id")->assertOk();
    expect(Driver::find($id))->toBeNull();
});

it('valide les formats et l’unicité du matricule et du badge', function () use ($as, $payload) {
    $as('flotte@demo.ma')->postJson('/api/drivers', $payload(['employee_number' => 'CH-1012', 'badge_number' => '4410']))
        ->assertStatus(422)->assertJsonValidationErrors(['employee_number', 'badge_number']);
});

it('refuse de supprimer un chauffeur en service', function () use ($as) {
    $d = Driver::where('employee_number', 'CH-1012')->first();
    DriverShift::create(['driver_id' => $d->id, 'vehicle_id' => $d->vehicle->id, 'start_at' => now()]);
    $as('admin@demo.ma')->deleteJson("/api/drivers/{$d->id}")->assertStatus(422);
});

it('pagine et filtre la liste des chauffeurs', function () use ($as) {
    $as('operateur@demo.ma')->getJson('/api/drivers?per_page=10')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 14);
    $this->getJson('/api/drivers?tab=rest')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mehdi Alaoui');
    $this->getJson('/api/drivers?q=B-4410')->assertJsonCount(1, 'data');
    $this->postJson('/api/drivers', [])->assertForbidden();
    $this->get('/api/drivers/export')->assertForbidden();
});

it('renvoie le tableau de bord', function () use ($as) {
    $as('operateur@demo.ma')->getJson('/api/dashboard')->assertOk()
        ->assertJsonPath('data.kpis.total', 12)->assertJsonCount(7, 'data.week')->assertJsonCount(5, 'data.top')
        ->assertJsonStructure(['data' => ['kpis' => ['moving', 'stopped', 'offline', 'missions_in_progress', 'stops_today', 'distance_today_km'], 'activities']]);
});
