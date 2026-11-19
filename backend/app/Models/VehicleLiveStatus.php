<?php

namespace App\Models;

use App\Enums\LiveStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dernier état connu d'un camion (une ligne par camion). */
class VehicleLiveStatus extends Model
{
    protected $table = 'vehicle_live_status';
    protected $primaryKey = 'vehicle_id';
    public $incrementing = false;
    const CREATED_AT = null;

    protected $fillable = [
        'vehicle_id', 'latitude', 'longitude', 'speed_kmh', 'course', 'ignition', 'status',
        'status_since', 'address', 'geofence_id', 'today_distance_km', 'last_position_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'speed_kmh' => 'float',
            'course' => 'integer',
            'ignition' => 'boolean',
            'status' => LiveStatus::class,
            'status_since' => 'datetime',
            'today_distance_km' => 'float',
            'last_position_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }
}
