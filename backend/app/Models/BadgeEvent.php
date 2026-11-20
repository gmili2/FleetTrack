<?php

namespace App\Models;

use App\Enums\BadgeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Passage de badge sur le lecteur d'un camion. */
class BadgeEvent extends Model
{
    protected $fillable = ['badge_number', 'driver_id', 'vehicle_id', 'driver_shift_id', 'type', 'source', 'occurred_at', 'latitude', 'longitude', 'place'];

    protected function casts(): array
    {
        return ['type' => BadgeType::class, 'occurred_at' => 'datetime', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(DriverShift::class, 'driver_shift_id');
    }
}
