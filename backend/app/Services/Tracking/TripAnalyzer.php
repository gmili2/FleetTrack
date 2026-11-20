<?php

namespace App\Services\Tracking;

use App\Models\Position;
use App\Models\Setting;
use App\Support\Geo;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Synthèse d'une suite de positions : distance, temps de conduite / d'arrêt,
 * arrêts (au-delà du seuil « arrêt après 5 min »), vitesses.
 */
class TripAnalyzer
{
    /** Écart max entre deux points pris en compte dans les durées (au-delà : perte de signal). */
    private const MAX_GAP_S = 600;

    /** @param Collection<int, Position> $positions triées par recorded_at */
    public function summarize(Collection $positions): array
    {
        $moving = (float) Setting::get('thresholds.moving_kmh', 5);
        $stopMin = (int) Setting::get('thresholds.stop_minutes', 5) * 60;
        $distance = $driving = $stopped = 0.0;
        $max = 0.0;
        $stops = [];
        $stopStart = null;
        $prev = null;

        foreach ($positions as $p) {
            $max = max($max, $p->speed_kmh);
            if ($prev) {
                $gap = $p->recorded_at->getTimestamp() - $prev->recorded_at->getTimestamp();
                $distance += Geo::distanceM($prev->latitude, $prev->longitude, $p->latitude, $p->longitude);
                if ($gap <= self::MAX_GAP_S) {
                    $prev->speed_kmh > $moving ? $driving += $gap : $stopped += $gap;
                }
            }
            if ($p->speed_kmh <= $moving) {
                $stopStart ??= $p;
            } elseif ($stopStart) {
                $this->pushStop($stops, $stopStart, $p->recorded_at, $stopMin);
                $stopStart = null;
            }
            $prev = $p;
        }
        if ($stopStart && $prev) {
            $this->pushStop($stops, $stopStart, $prev->recorded_at, $stopMin, true);
        }

        return [
            'distance_km' => round($distance / 1000, 1),
            'driving_seconds' => (int) $driving,
            'stopped_seconds' => (int) $stopped,
            'stops_count' => count($stops),
            'stops' => $stops,
            'max_speed_kmh' => round($max),
            'avg_speed_kmh' => $driving > 0 ? round(($distance / 1000) / ($driving / 3600)) : 0,
            'points' => $positions->count(),
        ];
    }

    private function pushStop(array &$stops, Position $start, CarbonInterface $end, int $minSeconds, bool $ongoing = false): void
    {
        $duration = $end->getTimestamp() - $start->recorded_at->getTimestamp();
        if ($duration >= $minSeconds) {
            $stops[] = [
                'started_at' => $start->recorded_at->toIso8601String(),
                'ended_at' => $ongoing ? null : $end->toIso8601String(),
                'duration_seconds' => $duration,
                'latitude' => $start->latitude,
                'longitude' => $start->longitude,
            ];
        }
    }
}
