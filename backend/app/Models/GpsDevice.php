<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GpsDevice extends Model
{
    use HasFactory;

    protected $fillable = ['imei', 'model', 'sim_number', 'vehicle_id', 'is_active', 'installed_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'installed_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
