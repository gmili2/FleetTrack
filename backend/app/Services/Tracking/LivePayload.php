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
    public const WITH = ['liveStatus.geofence:id,name,type', 'currentDriver:id,first_name,last_name,phone,employee_number,badge_number', 'device:id,vehicle_id,imei,model', 'openShift.driver:id,first_name,last_name,phone,employee_number,badge_number'];

    public static function make(Vehicle $v, ?Mission $mission = null, bool $withMission = true): array
    {
        $l = $v->liveStatus;
        $status = $l?->status ?? LiveStatus::Offline;
        if ($withMission && $mission === null) {
            $mission = Mission::with(['origin:id,name', 'destination:id,name'])
                ->where('vehicle_id', $v->id)->where('status', MissionStatus::InProgress)->first();
        }

        // Le chauffeur suivi est celui qui a pointé sur le camion ; à défaut, le chauffeur affecté.
        $shift = $v->openShift;
        $driver = $shift?->driver ?? $v->currentDriver;

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
            'driver' => $driver ? [
                'id' => $driver->id,
                'name' => $driver->full_name,
                'phone' => $driver->phone,
                'employee_number' => $driver->employee_number,
                'badge_number' => $driver->badge_number,
                'on_shift' => $shift !== null && $shift->driver_id === $driver->id,
            ] : null,
            'shift' => $shift ? [
                'id' => $shift->id,
                'start_at' => $shift->start_at->toIso8601String(),
                'start_place' => $shift->start_place,
                'source' => $shift->source,
                'anomaly' => $shift->anomaly?->value,
                'unknown_badge' => $shift->driver_id === null ? $shift->badge_number : null,
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
