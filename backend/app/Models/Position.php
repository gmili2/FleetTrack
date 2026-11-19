<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Point GPS brut (une ligne par émission du boîtier). */
class Position extends Model
{
    public $timestamps = false;

    protected $fillable = ['vehicle_id', 'latitude', 'longitude', 'speed_kmh', 'course', 'ignition', 'recorded_at'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'speed_kmh' => 'float',
            'course' => 'integer',
            'ignition' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
