<?php

use App\Enums\DriverStatus;
use App\Models\Driver;
use App\Models\Geofence;
use App\Models\Mission;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));
$as = fn (string $email) => test()->actingAs(User::where('email', $email)->firstOrFail());

function missionPayload(string $cam, string $driverNo, array $over = []): array
{
    return array_merge([
        'vehicle_id' => Vehicle::where('name', $cam)->value('id'),
        'driver_id' => Driver::where('employee_number', $driverNo)->value('id'),
        'origin_geofence_id' => Geofence::where('type', 'depot')->value('id'),
        'destination_geofence_id' => Geofence::where('name', 'Chantier Lissasfa')->value('id'),
        'planned_start_at' => now()->addHour()->toIso8601String(),
        'planned_end_at' => now()->addHours(3)->toIso8601String(),
        'cargo' => 'Granulats 18 t',
    ], $over);
}

it('crée une mission avec une référence MIS-AAAA-xxxxx et un journal', function () use ($as) {
    $r = $as('operateur@demo.ma')->postJson('/api/missions', missionPayload('CAM-09', 'CH-1040'))->assertCreated()
        ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.events.0.message', 'Mission créée');
    expect($r->json('data.reference'))->toMatch('/^MIS-\d{4}-\d{5}$/');
});

it('enchaîne Démarrer → Terminer et libère le chauffeur', function () use ($as) {
    $id = $as('operateur@demo.ma')->postJson('/api/missions', missionPayload('CAM-09', 'CH-1040'))->json('data.id');
    $this->postJson("/api/missions/$id/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
    expect(Driver::where('employee_number', 'CH-1040')->first()->status)->toBe(DriverStatus::OnMission);
    $this->postJson("/api/missions/$id/comment", ['text' => 'Chargement terminé'])->assertOk()->assertJsonPath('data.events.0.message', 'Commentaire : Chargement terminé');
    $this->postJson("/api/missions/$id/complete")->assertOk()->assertJsonPath('data.status', 'completed');
    expect(Driver::where('employee_number', 'CH-1040')->first()->status)->toBe(DriverStatus::Available)
        ->and(Mission::find($id)->actual_end_at)->not->toBeNull();
});

it('interdit deux missions en cours pour un même camion ou un même chauffeur', function () use ($as) {
    // CAM-07 / Youssef Amrani ont déjà une mission en cours (seeder).
    $as('flotte@demo.ma');
    $sameTruck = $this->postJson('/api/missions', missionPayload('CAM-07', 'CH-1040'))->json('data.id');
    $this->postJson("/api/missions/$sameTruck/start")->assertStatus(422)->assertJsonPath('errors.mission.0', fn ($m) => str_contains($m, 'camion CAM-07'));
    $sameDriver = $this->postJson('/api/missions', missionPayload('CAM-09', 'CH-1012'))->json('data.id');
    $this->postJson("/api/missions/$sameDriver/start")->assertStatus(422)->assertJsonPath('errors.mission.0', fn ($m) => str_contains($m, 'Youssef Amrani'));
});

it('annule avec un motif obligatoire et refuse les transitions invalides', function () use ($as) {
    $id = $as('flotte@demo.ma')->postJson('/api/missions', missionPayload('CAM-09', 'CH-1040'))->json('data.id');
    $this->postJson("/api/missions/$id/complete")->assertStatus(422);
    $this->postJson("/api/missions/$id/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->postJson("/api/missions/$id/cancel", ['reason' => 'Client absent'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/missions/$id/start")->assertStatus(422);
});

it('valide départ ≠ destination et fin après début', function () use ($as) {
    $depot = Geofence::where('type', 'depot')->value('id');
    $as('flotte@demo.ma')->postJson('/api/missions', missionPayload('CAM-09', 'CH-1040', [
        'destination_geofence_id' => $depot, 'planned_end_at' => now()->subDay()->toIso8601String(),
    ]))->assertStatus(422)->assertJsonValidationErrors(['destination_geofence_id', 'planned_end_at']);
});

it('applique les droits : l’opérateur ne supprime ni n’exporte', function () use ($as) {
    $id = $as('operateur@demo.ma')->postJson('/api/missions', missionPayload('CAM-09', 'CH-1040'))->json('data.id');
    $this->deleteJson("/api/missions/$id")->assertForbidden();
    $this->get('/api/missions/export')->assertForbidden();
    $as('flotte@demo.ma')->deleteJson("/api/missions/$id")->assertOk();
    $this->get('/api/missions/export')->assertOk();
    $this->getJson('/api/missions?status=in_progress')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.counts.in_progress', 3);
});
