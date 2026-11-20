<?php

namespace App\Services\Tracking;

use App\Models\DriverShift;
use App\Models\Position;

/** Mise en forme d'un service (pointage) pour l'API, et trace GPS associée. */
class ShiftPresenter
{
    public static function row(DriverShift $s): array
    {
        $end = $s->end_at ?? now();

        return [
            'id' => $s->id,
            'source' => $s->source,
            'driver' => $s->driver ? ['id' => $s->driver->id, 'name' => $s->driver->full_name, 'employee_number' => $s->driver->employee_number, 'badge_number' => $s->driver->badge_number] : null,
            'badge_number' => $s->badge_number,
            'vehicle' => $s->vehicle ? ['id' => $s->vehicle->id, 'name' => $s->vehicle->name, 'plate_number' => $s->vehicle->plate_number] : null,
            'start_at' => $s->start_at->toIso8601String(),
            'start_place' => $s->start_place,
            'end_at' => $s->end_at?->toIso8601String(),
            'end_place' => $s->end_place,
            'open' => $s->end_at === null,
            'duration_seconds' => $s->duration_seconds ?? max(0, $end->getTimestamp() - $s->start_at->getTimestamp()),
            'distance_km' => $s->distance_km,
            'anomaly' => $s->anomaly?->value,
            'anomaly_label' => $s->anomaly?->label(),
        ];
    }

    /** Trace GPS du service (au plus ~1 500 points) et synthèse. */
    public static function track(DriverShift $s, TripAnalyzer $analyzer): array
    {
        $positions = Position::where('vehicle_id', $s->vehicle_id)
            ->whereBetween('recorded_at', [$s->start_at, $s->end_at ?? now()])
            ->orderBy('recorded_at')->get(['latitude', 'longitude', 'speed_kmh', 'recorded_at']);
        $summary = $analyzer->summarize($positions);
        $step = max(1, (int) ceil($positions->count() / 1500));

        return [
            'shift' => self::row($s),
            'summary' => $summary,
            'points' => $positions->values()->filter(fn ($p, $i) => $i % $step === 0 || $i === $positions->count() - 1)
                ->map(fn ($p) => [$p->latitude, $p->longitude, (int) round($p->speed_kmh), $p->recorded_at->toIso8601String()])->values(),
        ];
    }
}
