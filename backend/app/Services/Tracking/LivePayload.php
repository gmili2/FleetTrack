<?php

namespace App\Services\Tracking;

use App\Enums\LiveStatus;
use App\Enums\MissionStatus;
use App\Models\Mission;
use App\Models\Vehicle;

/** Représentation « temps réel » d'un camion, commune à l'API et aux diffusions Reverb. */
class LivePayload
{
    /** Relations à précharger. */
    public const WITH = ['liveStatus.geofence:id,name,type', 'currentDriver:id,first_name,last_name,phone,employee_number,badge_number', 'device:id,vehicle_id,imei,model'];

    public static function make(Vehicle $v, ?Mission $mission = null, bool $withMission = true): array
    {
        $l = $v->liveStatus;
        $status = $l?->status ?? LiveStatus::Offline;
        if ($withMission && $mission === null) {
            $mission = Mission::with(['origin:id,name', 'destination:id,name'])
                ->where('vehicle_id', $v->id)->where('status', MissionStatus::InProgress)->first();
        }

        return [
            'id' => $v->id,
            'name' => $v->name,
            'plate_number' => $v->plate_number,
            'brand' => $v->brand,
            'model' => $v->model,
            'type' => $v->type?->value,
            'type_label' => $v->type?->label(),
            'vehicle_status' => $v->status?->value,
            'speed_limit_kmh' => $v->speed_limit_kmh,
            'status' => $status->value,
            'status_label' => $status->label(),
            'status_since' => $l?->status_since?->toIso8601String(),
            'latitude' => $l?->latitude,
            'longitude' => $l?->longitude,
            'speed_kmh' => $l ? round($l->speed_kmh) : 0,
            'course' => $l?->course ?? 0,
            'ignition' => (bool) $l?->ignition,
            'address' => $l?->address,
            'zone' => $l?->geofence ? ['id' => $l->geofence->id, 'name' => $l->geofence->name, 'type' => $l->geofence->type->value] : null,
            'today_distance_km' => round($l?->today_distance_km ?? 0, 1),
            'last_position_at' => $l?->last_position_at?->toIso8601String(),
            'driver' => $v->currentDriver ? [
                'id' => $v->currentDriver->id,
                'name' => $v->currentDriver->full_name,
                'phone' => $v->currentDriver->phone,
                'employee_number' => $v->currentDriver->employee_number,
                'badge_number' => $v->currentDriver->badge_number,
            ] : null,
            'device' => $v->device ? ['imei' => $v->device->imei, 'model' => $v->device->model] : null,
            'mission' => $mission ? [
                'id' => $mission->id,
                'reference' => $mission->reference,
                'status' => $mission->status->value,
                'origin' => $mission->origin?->name,
                'destination' => $mission->destination?->name,
                'actual_start_at' => $mission->actual_start_at?->toIso8601String(),
                'planned_end_at' => $mission->planned_end_at?->toIso8601String(),
            ] : null,
        ];
    }
}
