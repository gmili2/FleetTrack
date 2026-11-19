<?php

namespace App\Models;

use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'plate_number', 'name', 'brand', 'model', 'type', 'year', 'status',
        'speed_limit_kmh', 'odometer_km', 'current_driver_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
            'status' => VehicleStatus::class,
            'year' => 'integer',
            'speed_limit_kmh' => 'integer',
            'odometer_km' => 'integer',
        ];
    }

    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'current_driver_id');
    }

    public function device(): HasOne
    {
        return $this->hasOne(GpsDevice::class);
    }

    public function liveStatus(): HasOne
    {
        return $this->hasOne(VehicleLiveStatus::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function zoneVisits(): HasMany
    {
        return $this->hasMany(VehicleZoneVisit::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(DriverShift::class);
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function gpsEvents(): HasMany
    {
        return $this->hasMany(GpsEvent::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(DailyVehicleStat::class);
    }
}
