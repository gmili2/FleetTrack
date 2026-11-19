<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Paramètres de l'application, stockés en JSON et mis en cache.
 * Setting::get('thresholds.moving_kmh'), Setting::put('simulator.running', true).
 */
class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    /** Valeurs par défaut (cahier des charges). */
    public const DEFAULTS = [
        'thresholds.moving_kmh' => 5,           // en mouvement au-delà de 5 km/h
        'thresholds.offline_minutes' => 10,     // hors ligne après 10 min sans position
        'thresholds.stop_minutes' => 5,         // arrêt compté au-delà de 5 min
        'simulator.running' => false,
        'simulator.interval_seconds' => 5,
    ];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('settings', fn () => static::query()->pluck('value', 'key')->all());

        return $all[$key] ?? $default ?? self::DEFAULTS[$key] ?? null;
    }

    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings');
    }
}
