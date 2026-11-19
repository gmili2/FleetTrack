<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Statistiques journalières d'un camion (calculées chaque nuit). */
class DailyVehicleStat extends Model
{
    protected $fillable = ['vehicle_id', 'date', 'distance_km', 'driving_seconds', 'stopped_seconds', 'stops_count', 'max_speed_kmh', 'avg_speed_kmh'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'distance_km' => 'float',
            'driving_seconds' => 'integer',
            'stopped_seconds' => 'integer',
            'stops_count' => 'integer',
            'max_speed_kmh' => 'float',
            'avg_speed_kmh' => 'float',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }
}
