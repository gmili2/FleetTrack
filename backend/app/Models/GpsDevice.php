<?php

namespace App\Models;

use App\Support\GpsConfig;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Carte GPS (boîtier) : installée sur au plus un camion, choisie par le chauffeur au pointage. */
class GpsDevice extends Model
{
    use HasFactory;

    protected $fillable = ['label', 'imei', 'model', 'sim_number', 'operator', 'vehicle_id', 'is_active', 'config', 'notes', 'installed_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'config' => 'array', 'installed_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(DriverShift::class);
    }

    /** Configuration complète (avec les valeurs par défaut). */
    public function configuration(): array
    {
        return GpsConfig::merge($this->config);
    }

    /** Nom affiché : libellé ou fin de l'IMEI. */
    public function displayName(): string
    {
        return $this->label ?: 'Carte …'.substr($this->imei, -6);
    }
}
