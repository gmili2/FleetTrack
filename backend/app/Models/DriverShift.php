<?php

namespace App\Models;

use App\Enums\ShiftAnomaly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Service d'un chauffeur : du badge d'entrée au badge de sortie. */
class DriverShift extends Model
{
    protected $fillable = [
        'driver_id', 'vehicle_id', 'badge_number', 'source',
        'start_at', 'start_lat', 'start_lng', 'start_place',
        'end_at', 'end_lat', 'end_lng', 'end_place',
        'duration_seconds', 'distance_km', 'anomaly',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'start_lat' => 'float', 'start_lng' => 'float',
            'end_lat' => 'float', 'end_lng' => 'float',
            'duration_seconds' => 'integer',
            'distance_km' => 'float',
            'anomaly' => ShiftAnomaly::class,
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function badgeEvents(): HasMany
    {
        return $this->hasMany(BadgeEvent::class);
    }

    public function scopeOpen(Builder $q): void
    {
        $q->whereNull('end_at');
    }

    public function isOpen(): bool
    {
        return $this->end_at === null;
    }
}
