<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Passage d'un camion dans une zone (entrée, sortie, durée). */
class VehicleZoneVisit extends Model
{
    protected $fillable = ['vehicle_id', 'geofence_id', 'driver_id', 'entered_at', 'exited_at', 'duration_seconds'];

    protected function casts(): array
    {
        return ['entered_at' => 'datetime', 'exited_at' => 'datetime', 'duration_seconds' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function scopeOngoing(Builder $q): void
    {
        $q->whereNull('exited_at');
    }

    /** Ferme la visite et calcule la durée. */
    public function close(\DateTimeInterface $at): void
    {
        $this->exited_at = $at;
        $this->duration_seconds = max(0, $at->getTimestamp() - $this->entered_at->getTimestamp());
        $this->save();
    }
}
