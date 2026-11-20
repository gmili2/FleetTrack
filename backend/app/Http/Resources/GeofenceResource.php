<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Geofence */
class GeofenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'shape' => $this->shape->value,
            'shape_label' => $this->shape->label(),
            'center' => [$this->center_lat, $this->center_lng],
            'center_lat' => $this->center_lat,
            'center_lng' => $this->center_lng,
            'radius_m' => $this->radius_m,
            'coordinates' => $this->coordinates,
            'color' => $this->color,
            'customer' => $this->customer ? ['id' => $this->customer->id, 'code' => $this->customer->code, 'name' => $this->customer->name] : null,
            'min_visit_seconds' => $this->min_visit_seconds,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'visits_today' => $this->when(isset($this->visits_today), fn () => (int) $this->visits_today),
            'vehicles_inside' => $this->when(isset($this->vehicles_inside), fn () => (int) $this->vehicles_inside),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
