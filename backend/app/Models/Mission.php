<?php

namespace App\Models;

use App\Enums\MissionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mission extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'vehicle_id', 'driver_id', 'customer_id', 'origin_geofence_id', 'destination_geofence_id',
        'planned_start_at', 'planned_end_at', 'actual_start_at', 'actual_end_at',
        'status', 'cargo', 'distance_km', 'notes', 'cancel_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => MissionStatus::class,
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'distance_km' => 'float',
        ];
    }

    /** Référence suivante de l'année : MIS-2026-00001, MIS-2026-00002… */
    public static function nextReference(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $prefix = "MIS-$year-";
        $last = static::withTrashed()->where('reference', 'like', "$prefix%")->orderByDesc('reference')->value('reference');
        $n = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Geofence::class, 'origin_geofence_id')->withTrashed();
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Geofence::class, 'destination_geofence_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MissionEvent::class)->orderByDesc('occurred_at');
    }

    public function scopeStatus(Builder $q, MissionStatus $status): void
    {
        $q->where('status', $status);
    }

    /** Mission en retard : en cours au-delà de la fin prévue, ou en attente au-delà du début prévu. */
    public function isLate(): bool
    {
        return match ($this->status) {
            MissionStatus::InProgress => $this->planned_end_at->isPast(),
            MissionStatus::Pending => $this->planned_start_at->isPast(),
            default => false,
        };
    }
}
