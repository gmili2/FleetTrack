<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Driver */
class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shift = $this->relationLoaded('openShift') ? $this->openShift : null;
        $vehicle = $shift?->vehicle ?? $this->vehicle;

        return [
            'id' => $this->id,
            'name' => $this->full_name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'initials' => mb_strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1)),
            'employee_number' => $this->employee_number,
            'badge_number' => $this->badge_number,
            'phone' => $this->phone,
            'license_number' => $this->license_number,
            'license_category' => $this->license_category,
            'license_expires_at' => $this->license_expires_at?->toDateString(),
            'license_expired' => $this->license_expires_at?->isPast() ?? false,
            'license_expires_soon' => $this->licenseExpiresSoon(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'hired_at' => $this->hired_at?->toDateString(),
            'notes' => $this->notes,
            'vehicle' => $vehicle ? ['id' => $vehicle->id, 'name' => $vehicle->name, 'plate_number' => $vehicle->plate_number] : null,
            'on_shift' => $shift ? ['id' => $shift->id, 'start_at' => $shift->start_at->toIso8601String(), 'source' => $shift->source, 'vehicle_id' => $shift->vehicle_id] : null,
            'shifts_this_month' => (int) ($this->shifts_this_month ?? 0),
        ];
    }
}
