<?php

namespace App\Services\Tracking;

use App\Enums\MissionStatus;
use App\Models\Geofence;
use App\Models\Mission;
use App\Models\Vehicle;
use App\Models\VehicleZoneVisit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Visites d'une zone sur une période : indicateurs, temps par camion, lignes (avec mission associée). */
class ZoneVisitReport
{
    public const TZ = 'Africa/Casablanca';

    public function query(Geofence $zone, CarbonImmutable $from, CarbonImmutable $to, ?int $vehicleId): Builder
    {
        return VehicleZoneVisit::with(['vehicle:id,name,plate_number', 'driver:id,first_name,last_name'])
            ->where('geofence_id', $zone->id)
            ->where('entered_at', '<=', $to)
            ->where(fn ($q) => $q->whereNull('exited_at')->orWhere('exited_at', '>=', $from))
            ->when($vehicleId, fn ($q) => $q->where('vehicle_id', $vehicleId))
            ->latest('entered_at');
    }

    public function stats(Collection $visits): array
    {
        $durations = $visits->map(fn ($v) => $this->duration($v))->sort()->values();
        $n = $durations->count();
        $byVehicle = $visits->groupBy('vehicle_id')->map(fn ($g) => [
            'vehicle' => $g->first()->vehicle?->name ?? '—',
            'vehicle_id' => $g->first()->vehicle_id,
            'seconds' => $g->sum(fn ($v) => $this->duration($v)),
            'visits' => $g->count(),
        ])->sortByDesc('seconds')->values();
        $top = $byVehicle->take(6);
        $others = $byVehicle->slice(6);
        if ($others->isNotEmpty()) {
            $top->push(['vehicle' => 'Autres ('.$others->count().')', 'vehicle_id' => null, 'seconds' => $others->sum('seconds'), 'visits' => $others->sum('visits')]);
        }

        return [
            'visits' => $n,
            'ongoing' => $visits->whereNull('exited_at')->count(),
            'avg_seconds' => $n ? (int) round($durations->avg()) : 0,
            'median_seconds' => $n ? (int) ($n % 2 ? $durations[intdiv($n, 2)] : ($durations[$n / 2 - 1] + $durations[$n / 2]) / 2) : 0,
            'total_seconds' => (int) $durations->sum(),
            'vehicles' => $byVehicle->count(),
            'fleet_size' => Vehicle::count(),
            'by_vehicle' => $top->values(),
        ];
    }

    public function row(VehicleZoneVisit $v, Geofence $zone): array
    {
        // Mission dont la zone est le départ ou la destination, active pendant la visite.
        $mission = Mission::where('vehicle_id', $v->vehicle_id)
            ->where(fn ($q) => $q->where('origin_geofence_id', $zone->id)->orWhere('destination_geofence_id', $zone->id))
            ->whereIn('status', [MissionStatus::InProgress, MissionStatus::Completed])
            ->where('actual_start_at', '<=', $v->exited_at ?? now())
            ->where(fn ($q) => $q->whereNull('actual_end_at')->orWhere('actual_end_at', '>=', $v->entered_at->copy()->subHour()))
            ->orderByDesc('actual_start_at')->first(['id', 'reference', 'status']);

        return [
            'id' => $v->id,
            'vehicle' => $v->vehicle ? ['id' => $v->vehicle->id, 'name' => $v->vehicle->name, 'plate_number' => $v->vehicle->plate_number] : null,
            'driver' => $v->driver?->full_name,
            'entered_at' => $v->entered_at->toIso8601String(),
            'exited_at' => $v->exited_at?->toIso8601String(),
            'duration_seconds' => $this->duration($v),
            'ongoing' => $v->exited_at === null,
            'mission' => $mission ? ['id' => $mission->id, 'reference' => $mission->reference, 'status' => $mission->status->value] : null,
        ];
    }

    public function duration(VehicleZoneVisit $v): int
    {
        return $v->duration_seconds ?? max(0, now()->getTimestamp() - $v->entered_at->getTimestamp());
    }

    public static function fmt(int $s): string
    {
        return sprintf('%d h %02d', intdiv($s, 3600), intdiv($s % 3600, 60));
    }
}
