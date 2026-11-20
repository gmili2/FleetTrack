<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Vehicle */
class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $live = $this->liveStatus;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'plate_number' => $this->plate_number,
            'brand' => $this->brand,
            'model' => $this->model,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'year' => $this->year,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'speed_limit_kmh' => $this->speed_limit_kmh,
            'odometer_km' => $this->odometer_km,
            'notes' => $this->notes,
            'driver' => $this->currentDriver ? ['id' => $this->currentDriver->id, 'name' => $this->currentDriver->full_name, 'employee_number' => $this->currentDriver->employee_number] : null,
            'device' => $this->device ? [
                'id' => $this->device->id, 'imei' => $this->device->imei, 'model' => $this->device->model, 'sim_number' => $this->device->sim_number,
                'installed_at' => $this->device->installed_at?->toIso8601String(), 'last_seen_at' => $this->device->last_seen_at?->toIso8601String(),
            ] : null,
            'live' => $live ? [
                'status' => $live->status->value, 'status_label' => $live->status->label(),
                'latitude' => $live->latitude, 'longitude' => $live->longitude, 'speed_kmh' => round($live->speed_kmh),
                'address' => $live->address, 'today_distance_km' => round($live->today_distance_km, 1),
                'last_position_at' => $live->last_position_at?->toIso8601String(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
