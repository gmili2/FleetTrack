<?php

namespace App\Services\Tracking;

use App\Models\DailyVehicleStat;
use App\Models\Position;
use Carbon\CarbonImmutable;

/** Statistiques journalières par camion (distance, conduite, arrêts, vitesses), calculées depuis les positions. */
class DailyStatsService
{
    public const TZ = 'Africa/Casablanca';

    public function __construct(private TripAnalyzer $analyzer) {}

    /** @return int nombre de camions traités */
    public function compute(CarbonImmutable $date): int
    {
        $start = $date->setTimezone(self::TZ)->startOfDay();
        [$from, $to] = [$start->utc(), $start->addDay()->utc()];
        $vehicleIds = Position::whereBetween('recorded_at', [$from, $to])->distinct()->pluck('vehicle_id');

        foreach ($vehicleIds as $id) {
            $positions = Position::where('vehicle_id', $id)->where('recorded_at', '>=', $from)->where('recorded_at', '<', $to)
                ->orderBy('recorded_at')->get(['latitude', 'longitude', 'speed_kmh', 'recorded_at']);
            $s = $this->analyzer->summarize($positions);
            DailyVehicleStat::updateOrCreate(['vehicle_id' => $id, 'date' => $start->toDateString()], [
                'distance_km' => $s['distance_km'],
                'driving_seconds' => $s['driving_seconds'],
                'stopped_seconds' => $s['stopped_seconds'],
                'stops_count' => $s['stops_count'],
                'max_speed_kmh' => $s['max_speed_kmh'],
                'avg_speed_kmh' => $s['avg_speed_kmh'],
            ]);
        }

        return $vehicleIds->count();
    }
}
