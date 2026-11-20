<?php

namespace App\Services\Tracking;

use App\Models\Position;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Support\Places;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Historique d'un camion sur une période : tracé, synthèse et chronologie
 * (départ A, trajets, arrêts numérotés, arrivée B), pour le GPS Replay.
 */
class HistoryService
{
    private const MAX_POINTS = 6000;

    public function __construct(private TripAnalyzer $analyzer, private GeofenceDetector $zones) {}

    public function build(Vehicle $vehicle, CarbonInterface $from, CarbonInterface $to): array
    {
        $all = Position::where('vehicle_id', $vehicle->id)->whereBetween('recorded_at', [$from, $to])->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'speed_kmh', 'course', 'ignition', 'recorded_at'])->values();
        $moving = (float) Setting::get('thresholds.moving_kmh', 5);

        // On cadre le trajet du premier au dernier mouvement (le stationnement de nuit n'est pas un « arrêt »).
        $first = $all->search(fn ($p) => $p->speed_kmh > $moving);
        if ($first === false) {
            return ['points' => [], 'summary' => null, 'timeline' => [], 'positions_total' => $all->count(), 'parked_at' => $this->place($all->last())];
        }
        $last = $all->keys()->reverse()->first(fn ($i) => $all[$i]->speed_kmh > $moving);
        $trip = $all->slice(max(0, $first - 1), min($all->count(), $last + 2) - max(0, $first - 1))->values();

        $summary = $this->analyzer->summarize($trip);
        $timeline = $this->timeline($trip, $summary['stops']);
        unset($summary['stops']);
        $start = $trip->first();
        $end = $trip->last();
        $summary['departure'] = ['at' => $start->recorded_at->toIso8601String(), 'place' => $this->place($start)];
        $summary['arrival'] = ['at' => $end->recorded_at->toIso8601String(), 'place' => $this->place($end), 'ongoing' => $end->recorded_at->gt(now()->subMinutes(2))];

        $step = max(1, (int) ceil($trip->count() / self::MAX_POINTS));

        return [
            'points' => $trip->filter(fn ($p, $i) => $i % $step === 0 || $i === $trip->count() - 1)->values()
                ->map(fn ($p) => [round($p->latitude, 6), round($p->longitude, 6), (int) round($p->speed_kmh), $p->course, $p->recorded_at->getTimestamp()]),
            'summary' => $summary,
            'timeline' => $timeline,
            'positions_total' => $all->count(),
        ];
    }

    /** Alternance trajets / arrêts entre le départ (A) et l'arrivée (B). */
    private function timeline(Collection $trip, array $stops): array
    {
        $items = [['kind' => 'departure', 'at' => $trip->first()->recorded_at->toIso8601String(), 'place' => $this->place($trip->first())]];
        $cursor = $trip->first()->recorded_at;
        foreach ($stops as $i => $s) {
            $sStart = \Carbon\CarbonImmutable::parse($s['started_at']);
            $items[] = $this->segment($trip, $cursor, $sStart);
            $zone = $this->zones->zonesAt($s['latitude'], $s['longitude'])->first();
            $items[] = [
                'kind' => 'stop', 'n' => $i + 1, 'started_at' => $s['started_at'], 'ended_at' => $s['ended_at'], 'duration_seconds' => $s['duration_seconds'],
                'place' => $zone?->name ?? Places::label($s['latitude'], $s['longitude']), 'in_zone' => $zone !== null,
                'latitude' => $s['latitude'], 'longitude' => $s['longitude'],
            ];
            $cursor = $s['ended_at'] ? \Carbon\CarbonImmutable::parse($s['ended_at']) : $sStart;
        }
        $items[] = $this->segment($trip, $cursor, $trip->last()->recorded_at);
        $items[] = ['kind' => 'arrival', 'at' => $trip->last()->recorded_at->toIso8601String(), 'place' => $this->place($trip->last())];

        return array_values(array_filter($items, fn ($it) => $it['kind'] !== 'trip' || $it['duration_seconds'] > 0));
    }

    private function segment(Collection $trip, CarbonInterface $from, CarbonInterface $to): array
    {
        $s = $this->analyzer->summarize($trip->filter(fn ($p) => $p->recorded_at->gte($from) && $p->recorded_at->lte($to))->values());

        return [
            'kind' => 'trip', 'started_at' => $from->toIso8601String(), 'ended_at' => $to->toIso8601String(),
            'duration_seconds' => max(0, $to->getTimestamp() - $from->getTimestamp()),
            'distance_km' => $s['distance_km'], 'avg_speed_kmh' => $s['avg_speed_kmh'], 'max_speed_kmh' => $s['max_speed_kmh'],
        ];
    }

    private function place(?Position $p): ?string
    {
        return $p ? ($this->zones->zonesAt($p->latitude, $p->longitude)->first()?->name ?? Places::label($p->latitude, $p->longitude)) : null;
    }
}
