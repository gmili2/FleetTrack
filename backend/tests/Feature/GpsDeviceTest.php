<?php

use App\Models\DriverShift;
use App\Models\GpsDevice;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\DatabaseSeeder;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::where('email', 'flotte@demo.ma')->firstOrFail());
});

function cardPayload(array $over = []): array
{
    return array_merge([
        'label' => 'gps-30', 'imei' => '86 1111 2222 3333 4', 'model' => 'Teltonika FMB920', 'sim_number' => '+212 7 11 22 33 44', 'operator' => 'orange',
        'vehicle_id' => null, 'is_active' => true, 'notes' => null,
        'config' => ['protocol' => 'gt06', 'server_host' => 'gps.exemple.ma', 'server_port' => 5023, 'apn' => 'internet.orange.ma',
            'interval_moving_s' => 15, 'interval_stopped_s' => 600, 'angle_change_deg' => 25, 'distance_m' => 150, 'overspeed_kmh' => 85,
            'ignition_detection' => true, 'harsh_driving' => true],
    ], $over);
}

it('liste les cartes avec leurs compteurs', function () {
    $this->getJson('/api/gps-devices?per_page=50')->assertOk()
        ->assertJsonPath('meta.counts.all', 15)->assertJsonPath('meta.counts.installed', 12)->assertJsonPath('meta.counts.free', 2)->assertJsonPath('meta.counts.inactive', 1)
        ->assertJsonPath('data.0.name', 'GPS-01')->assertJsonPath('data.0.config.protocol', 'teltonika');
    $this->getJson('/api/gps-devices?tab=free')->assertOk()->assertJsonCount(2, 'data');
});

it('crée une carte avec sa configuration, la modifie puis la supprime', function () {
    $id = $this->postJson('/api/gps-devices', cardPayload())->assertCreated()
        ->assertJsonPath('data.label', 'GPS-30')->assertJsonPath('data.imei', '861111222233334')
        ->assertJsonPath('data.config.protocol', 'gt06')->assertJsonPath('data.config.overspeed_kmh', 85)->assertJsonPath('data.vehicle', null)
        ->json('data.id');

    $this->putJson("/api/gps-devices/$id", cardPayload(['config' => array_merge(cardPayload()['config'], ['interval_moving_s' => 5, 'overspeed_kmh' => null])]))
        ->assertOk()->assertJsonPath('data.config.interval_moving_s', 5)->assertJsonPath('data.config.overspeed_kmh', null);

    $this->deleteJson("/api/gps-devices/$id")->assertOk();
    expect(GpsDevice::find($id))->toBeNull();
});

it('valide l’IMEI et la configuration', function () {
    $this->postJson('/api/gps-devices', cardPayload(['imei' => '123', 'config' => array_merge(cardPayload()['config'], ['server_port' => 70000, 'protocol' => 'xyz'])]))
        ->assertStatus(422)->assertJsonValidationErrors(['imei', 'config.server_port', 'config.protocol']);
    $this->postJson('/api/gps-devices', cardPayload(['imei' => GpsDevice::where('label', 'GPS-01')->value('imei')]))->assertStatus(422)->assertJsonValidationErrors('imei');
});

it('installe une carte sur un camion en retirant l’ancienne carte', function () {
    $truck = Vehicle::where('name', 'CAM-09')->first();
    $old = GpsDevice::where('vehicle_id', $truck->id)->first();
    $spare = GpsDevice::where('label', 'GPS-13')->first();

    $this->putJson("/api/gps-devices/{$spare->id}", cardPayload(['label' => 'GPS-13', 'imei' => $spare->imei, 'vehicle_id' => $truck->id]))
        ->assertOk()->assertJsonPath('data.vehicle.name', 'CAM-09');
    expect($old->fresh()->vehicle_id)->toBeNull()->and($truck->fresh()->device->id)->toBe($spare->id);
});

it('bloque le déplacement ou la suppression d’une carte en service', function () {
    $truck = Vehicle::where('name', 'CAM-09')->first();
    $card = GpsDevice::where('vehicle_id', $truck->id)->first();
    DriverShift::create(['driver_id' => $truck->current_driver_id, 'vehicle_id' => $truck->id, 'gps_device_id' => $card->id, 'source' => 'app', 'start_at' => now()->subHour()]);

    $this->putJson("/api/gps-devices/{$card->id}", cardPayload(['label' => $card->label, 'imei' => $card->imei, 'vehicle_id' => null]))->assertStatus(422)->assertJsonValidationErrors('vehicle_id');
    $this->deleteJson("/api/gps-devices/{$card->id}")->assertStatus(422);
    $this->getJson('/api/gps-devices?tab=in_service')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.in_service.driver', $truck->currentDriver->full_name);
});

it('donne la lecture seule à l’opérateur', function () {
    $this->actingAs(User::where('email', 'operateur@demo.ma')->first());
    $this->getJson('/api/gps-devices')->assertOk();
    $this->postJson('/api/gps-devices', cardPayload())->assertForbidden();
});

it('applique le seuil de survitesse de la carte et met à jour son dernier signal', function () {
    $truck = Vehicle::where('name', 'CAM-09')->first(); // vitesse max 80 km/h
    $card = GpsDevice::where('vehicle_id', $truck->id)->first();
    $card->update(['config' => array_merge($card->configuration(), ['overspeed_kmh' => 60]), 'last_seen_at' => null]);
    $live = $truck->liveStatus;

    app(\App\Services\Tracking\PositionIngestor::class)->ingest($truck, $live->latitude + 0.001, $live->longitude, 70, 90, true, now(), false);

    expect(\App\Models\GpsEvent::where('vehicle_id', $truck->id)->where('type', 'overspeed')->latest('id')->first()?->payload['limit_kmh'])->toBe(60)
        ->and($card->fresh()->last_seen_at)->not->toBeNull();
});
