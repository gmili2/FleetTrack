<?php

namespace App\Models;

use App\Enums\GeofenceShape;
use App\Enums\GeofenceType;
use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Geofence extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'type', 'shape', 'center_lat', 'center_lng', 'radius_m', 'coordinates',
        'color', 'customer_id', 'min_visit_seconds', 'address', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => GeofenceType::class,
            'shape' => GeofenceShape::class,
            'center_lat' => 'float',
            'center_lng' => 'float',
            'radius_m' => 'integer',
            'coordinates' => 'array',
            'min_visit_seconds' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function visits(): HasMany
    {
        return $this->hasMany(VehicleZoneVisit::class);
    }

    public function scopeActive(Builder $q): void
    {
        $q->where('is_active', true);
    }

    /** Le point (lat, lng) est-il dans la zone ? */
    public function contains(float $lat, float $lng): bool
    {
        return $this->shape === GeofenceShape::Circle
            ? Geo::inCircle($lat, $lng, $this->center_lat, $this->center_lng, (float) $this->radius_m)
            : Geo::inPolygon($lat, $lng, $this->coordinates ?? []);
    }
}
