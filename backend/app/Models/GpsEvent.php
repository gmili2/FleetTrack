<?php

namespace App\Models;

use App\Enums\GpsEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Événement détecté à partir des positions (zones, excès de vitesse, perte de signal…). */
class GpsEvent extends Model
{
    protected $fillable = ['type', 'vehicle_id', 'geofence_id', 'driver_id', 'occurred_at', 'payload'];

    protected function casts(): array
    {
        return ['type' => GpsEventType::class, 'occurred_at' => 'datetime', 'payload' => 'array'];
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
}
