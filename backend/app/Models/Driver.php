<?php

namespace App\Models;

use App\Enums\DriverStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Driver extends Model
{
    use HasFactory, SoftDeletes;

    /** Alerte d'expiration du permis (jours). */
    public const LICENSE_ALERT_DAYS = 30;

    protected $fillable = [
        'employee_number', 'badge_number', 'first_name', 'last_name', 'phone',
        'license_number', 'license_category', 'license_expires_at', 'status', 'hired_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => DriverStatus::class,
            'license_expires_at' => 'date',
            'hired_at' => 'date',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->first_name} {$this->last_name}"));
    }

    /** Camion actuellement confié au chauffeur. */
    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'current_driver_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(DriverShift::class);
    }

    public function openShift(): HasOne
    {
        return $this->hasOne(DriverShift::class)->whereNull('end_at')->latestOfMany('start_at');
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function badgeEvents(): HasMany
    {
        return $this->hasMany(BadgeEvent::class);
    }

    public function licenseExpiresSoon(): bool
    {
        return $this->license_expires_at !== null && $this->license_expires_at->lte(now()->addDays(self::LICENSE_ALERT_DAYS));
    }

    public function scopeLicenseExpiringWithin(Builder $q, int $days = self::LICENSE_ALERT_DAYS): void
    {
        $q->whereNotNull('license_expires_at')->whereDate('license_expires_at', '<=', now()->addDays($days));
    }
}
