<?php

namespace App\Services\Fleet;

use App\Enums\LiveStatus;
use App\Enums\MissionStatus;
use App\Models\AuditLog;
use App\Models\GpsDevice;
use App\Models\Mission;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleLiveStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Création / modification / suppression d'un camion, avec son boîtier GPS et son affectation. */
class VehicleService
{
    public function save(?Vehicle $vehicle, array $data): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data) {
            $isNew = $vehicle === null;
            $vehicle ??= new Vehicle;
            $before = $vehicle->exists ? $vehicle->only(['plate_number', 'name', 'status', 'current_driver_id', 'speed_limit_kmh']) : null;
            $previousDriver = $vehicle->current_driver_id;

            $vehicle->fill(collect($data)->only([
                'plate_number', 'name', 'brand', 'model', 'type', 'year', 'status', 'speed_limit_kmh', 'odometer_km', 'current_driver_id', 'notes',
            ])->all());
            $vehicle->odometer_km ??= 0;
            $vehicle->save();

            $this->syncDriver($vehicle, $previousDriver);
            $this->syncDevice($vehicle, $data);
            if ($isNew) {
                VehicleLiveStatus::create(['vehicle_id' => $vehicle->id, 'status' => LiveStatus::Offline]);
            }

            AuditLog::record($isNew ? 'created' : 'updated', $vehicle, ($isNew ? 'Camion créé : ' : 'Camion modifié : ').$vehicle->name,
                $before ? ['before' => $before, 'after' => $vehicle->only(array_keys($before))] : null);

            return $vehicle->fresh(['currentDriver', 'device', 'liveStatus']);
        });
    }

    public function delete(Vehicle $vehicle): void
    {
        if (Mission::where('vehicle_id', $vehicle->id)->where('status', MissionStatus::InProgress)->exists()) {
            throw ValidationException::withMessages(['vehicle' => "{$vehicle->name} a une mission en cours : terminez-la ou annulez-la avant de supprimer le camion."]);
        }
        DB::transaction(function () use ($vehicle) {
            VehicleAssignment::where('vehicle_id', $vehicle->id)->whereNull('ended_at')->update(['ended_at' => now()]);
            GpsDevice::where('vehicle_id', $vehicle->id)->update(['vehicle_id' => null]);
            $vehicle->update(['current_driver_id' => null]);
            $vehicle->delete();
            AuditLog::record('deleted', $vehicle, "Camion supprimé : {$vehicle->name}");
        });
    }

    /** Un chauffeur n'est affecté qu'à un camion à la fois ; l'historique des affectations est tenu à jour. */
    private function syncDriver(Vehicle $vehicle, ?int $previousDriver): void
    {
        if ($vehicle->current_driver_id === $previousDriver) {
            return;
        }
        $now = now();
        VehicleAssignment::where('vehicle_id', $vehicle->id)->whereNull('ended_at')->update(['ended_at' => $now]);
        if ($vehicle->current_driver_id) {
            // Le chauffeur quitte son ancien camion.
            Vehicle::where('current_driver_id', $vehicle->current_driver_id)->whereKeyNot($vehicle->id)->get()->each(function (Vehicle $other) use ($now) {
                $other->update(['current_driver_id' => null]);
                VehicleAssignment::where('vehicle_id', $other->id)->whereNull('ended_at')->update(['ended_at' => $now]);
            });
            VehicleAssignment::create(['vehicle_id' => $vehicle->id, 'driver_id' => $vehicle->current_driver_id, 'started_at' => $now]);
        }
    }

    private function syncDevice(Vehicle $vehicle, array $data): void
    {
        $device = $vehicle->device;
        if (empty($data['imei'])) {
            $device?->update(['vehicle_id' => null]);

            return;
        }
        // Un boîtier existant (non installé ailleurs) peut être réutilisé.
        $existing = GpsDevice::where('imei', $data['imei'])->first();
        if ($device && $device->imei !== $data['imei']) {
            $device->update(['vehicle_id' => null]);
            $device = null;
        }
        $device ??= $existing ?? new GpsDevice(['imei' => $data['imei'], 'installed_at' => now()]);
        $device->fill(['vehicle_id' => $vehicle->id, 'model' => $data['device_model'] ?? $device->model, 'sim_number' => $data['sim_number'] ?? $device->sim_number, 'is_active' => true]);
        $device->installed_at ??= now();
        $device->save();
    }
}
