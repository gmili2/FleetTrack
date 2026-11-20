<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Mission */
class MissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone' => $this->status->tone(),
            'late' => $this->isLate(),
            'vehicle' => $this->vehicle ? ['id' => $this->vehicle->id, 'name' => $this->vehicle->name, 'plate_number' => $this->vehicle->plate_number] : null,
            'driver' => $this->driver ? ['id' => $this->driver->id, 'name' => $this->driver->full_name, 'phone' => $this->driver->phone] : null,
            'customer' => $this->customer ? ['id' => $this->customer->id, 'name' => $this->customer->name, 'code' => $this->customer->code] : null,
            'origin' => $this->origin ? ['id' => $this->origin->id, 'name' => $this->origin->name] : null,
            'destination' => $this->destination ? ['id' => $this->destination->id, 'name' => $this->destination->name] : null,
            'planned_start_at' => $this->planned_start_at->toIso8601String(),
            'planned_end_at' => $this->planned_end_at->toIso8601String(),
            'actual_start_at' => $this->actual_start_at?->toIso8601String(),
            'actual_end_at' => $this->actual_end_at?->toIso8601String(),
            'cargo' => $this->cargo,
            'notes' => $this->notes,
            'cancel_reason' => $this->cancel_reason,
            'distance_km' => $this->distance_km,
            'created_by' => $this->creator?->name,
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type->value, 'label' => $e->type->label(), 'message' => $e->message,
                'author' => $e->user?->name, 'automatic' => $e->user_id === null, 'occurred_at' => $e->occurred_at->toIso8601String(),
            ])),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'operate' => $request->user()?->can('operate', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
