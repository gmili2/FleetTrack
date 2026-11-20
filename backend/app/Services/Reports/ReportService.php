<?php

namespace App\Services\Reports;

use App\Enums\GeofenceType;
use App\Enums\GpsEventType;
use App\Enums\MissionStatus;
use App\Models\DailyVehicleStat;
use App\Models\DriverShift;
use App\Models\GpsEvent;
use App\Models\Mission;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleZoneVisit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rapports : chaque rapport renvoie un graphique (labels + séries), des colonnes, des lignes et des totaux.
 * Les kilomètres et temps de conduite viennent de daily_vehicle_stats (calculées chaque nuit) ;
 * les visites et services viennent des tables de détail.
 */
class ReportService
{
    public const TZ = 'Africa/Casablanca';

    public const TYPES = [
        'mileage' => 'Kilométrage',
        'driving' => 'Temps de conduite et d’arrêt',
        'customers' => 'Temps passé chez les clients',
        'zones' => 'Visites par zone',
        'drivers' => 'Activité par chauffeur',
        'vehicles' => 'Activité par camion',
    ];

    private CarbonImmutable $from;
    private CarbonImmutable $to;
    private ?array $vehicleIds;
    private string $group;

    public function build(string $type, CarbonImmutable $from, CarbonImmutable $to, ?array $vehicleIds, string $group = 'day'): array
    {
        [$this->from, $this->to, $this->vehicleIds, $this->group] = [$from->startOfDay(), $to->endOfDay(), $vehicleIds ?: null, $group];
        $report = $this->{$type}();

        return ['type' => $type, 'title' => self::TYPES[$type], 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'group' => $group] + $report;
    }

    // ── Rapports ───────────────────────────────────────────────────────────────

    private function mileage(): array
    {
        $stats = $this->stats();
        $buckets = $this->buckets();
        $byBucket = $stats->groupBy(fn ($s) => $this->bucketKey(CarbonImmutable::parse($s->date->toDateString(), self::TZ)))->map->sum('distance_km');
        $days = max(1, $stats->pluck('date')->unique()->count());
        $total = $stats->sum('distance_km');

        return [
            'summary' => ['Total' => $this->km($total), 'Moyenne par jour' => $this->km($total / $days), 'Camions' => $stats->pluck('vehicle_id')->unique()->count()],
            'chart' => ['type' => 'bar', 'labels' => $buckets->values(), 'datasets' => [['label' => 'Distance (km)', 'data' => $buckets->keys()->map(fn ($k) => round($byBucket[$k] ?? 0))]], 'unit' => 'km'],
            ...$this->vehicleTable($stats),
        ];
    }

    private function driving(): array
    {
        $stats = $this->stats();
        $per = $this->perVehicle($stats)->sortByDesc('driving')->values();

        return [
            'summary' => ['Conduite' => $this->hours($stats->sum('driving_seconds')), 'Arrêt' => $this->hours($stats->sum('stopped_seconds')),
                'Part de conduite' => $this->pct($stats->sum('driving_seconds'), $stats->sum('driving_seconds') + $stats->sum('stopped_seconds'))],
            'chart' => ['type' => 'stacked', 'labels' => $per->pluck('name'), 'unit' => 'h', 'datasets' => [
                ['label' => 'Conduite', 'data' => $per->map(fn ($r) => round($r['driving'] / 3600, 1))],
                ['label' => 'Arrêt', 'data' => $per->map(fn ($r) => round($r['stopped'] / 3600, 1))],
            ]],
            ...$this->vehicleTable($stats),
        ];
    }

    private function customers(): array
    {
        $visits = $this->visits(GeofenceType::Customer)->load('geofence.customer');
        $byCustomer = $visits->groupBy(fn ($v) => $v->geofence?->customer?->name ?? $v->geofence?->name)->map(fn ($g, $name) => [
            'name' => $name, 'visits' => $g->count(), 'seconds' => $g->sum('duration_seconds'), 'avg' => (int) $g->avg('duration_seconds'),
            'vehicles' => $g->pluck('vehicle_id')->unique()->count(), 'max' => (int) $g->max('duration_seconds'),
        ])->sortByDesc('seconds')->values();

        return [
            'summary' => ['Temps total chez les clients' => $this->hours($visits->sum('duration_seconds')), 'Visites' => $visits->count(), 'Durée moyenne' => $this->hours((int) $visits->avg('duration_seconds'))],
            'chart' => ['type' => 'hbar', 'labels' => $byCustomer->pluck('name'), 'unit' => 'h', 'datasets' => [['label' => 'Temps sur place (h)', 'data' => $byCustomer->map(fn ($r) => round($r['seconds'] / 3600, 1))]]],
            'columns' => $this->cols(['name' => 'Client', 'visits' => ['Visites', 'right'], 'seconds' => ['Temps total', 'right', 'duration'], 'avg' => ['Durée moyenne', 'right', 'duration'], 'max' => ['Visite la plus longue', 'right', 'duration'], 'vehicles' => ['Camions', 'right']]),
            'rows' => $byCustomer->all(),
        ];
    }

    private function zones(): array
    {
        $visits = $this->visits()->load('geofence');
        $byZone = $visits->groupBy('geofence_id')->map(fn ($g) => [
            'name' => $g->first()->geofence?->name, 'type' => $g->first()->geofence?->type->label(), 'visits' => $g->count(),
            'seconds' => $g->sum('duration_seconds'), 'avg' => (int) $g->avg('duration_seconds'), 'vehicles' => $g->pluck('vehicle_id')->unique()->count(),
        ])->sortByDesc('visits')->values();

        return [
            'summary' => ['Visites' => $visits->count(), 'Zones visitées' => $byZone->count(), 'Temps total' => $this->hours($visits->sum('duration_seconds'))],
            'chart' => ['type' => 'hbar', 'labels' => $byZone->pluck('name'), 'unit' => 'visites', 'datasets' => [['label' => 'Visites', 'data' => $byZone->pluck('visits')]]],
            'columns' => $this->cols(['name' => 'Zone', 'type' => 'Type', 'visits' => ['Visites', 'right'], 'seconds' => ['Temps total', 'right', 'duration'], 'avg' => ['Durée moyenne', 'right', 'duration'], 'vehicles' => ['Camions', 'right']]),
            'rows' => $byZone->all(),
        ];
    }

    private function drivers(): array
    {
        $shifts = DriverShift::with('driver:id,first_name,last_name,employee_number')->whereNotNull('driver_id')
            ->whereBetween('start_at', [$this->from->utc(), $this->to->utc()])
            ->when($this->vehicleIds, fn ($q) => $q->whereIn('vehicle_id', $this->vehicleIds))->get();
        $missions = Mission::where('status', MissionStatus::Completed)->whereBetween('actual_end_at', [$this->from->utc(), $this->to->utc()])
            ->when($this->vehicleIds, fn ($q) => $q->whereIn('vehicle_id', $this->vehicleIds))->selectRaw('driver_id, count(*) c')->groupBy('driver_id')->pluck('c', 'driver_id');
        $rows = $shifts->groupBy('driver_id')->map(fn ($g, $id) => [
            'name' => $g->first()->driver?->full_name, 'employee_number' => $g->first()->driver?->employee_number, 'shifts' => $g->count(),
            'seconds' => (int) $g->sum('duration_seconds'), 'km' => round($g->sum('distance_km')), 'missions' => (int) ($missions[$id] ?? 0),
            'anomalies' => $g->whereNotNull('anomaly')->count(), 'avg' => (int) $g->whereNotNull('duration_seconds')->avg('duration_seconds'),
        ])->sortByDesc('km')->values();

        return [
            'summary' => ['Chauffeurs actifs' => $rows->count(), 'Services' => $shifts->count(), 'Anomalies de pointage' => $shifts->whereNotNull('anomaly')->count()],
            'chart' => ['type' => 'hbar', 'labels' => $rows->pluck('name'), 'unit' => 'km', 'datasets' => [['label' => 'Distance (km)', 'data' => $rows->pluck('km')]]],
            'columns' => $this->cols(['name' => 'Chauffeur', 'employee_number' => 'Matricule', 'shifts' => ['Services', 'right'], 'seconds' => ['Temps de service', 'right', 'duration'], 'avg' => ['Service moyen', 'right', 'duration'], 'km' => ['Km', 'right', 'number'], 'missions' => ['Missions', 'right'], 'anomalies' => ['Anomalies', 'right']]),
            'rows' => $rows->all(),
        ];
    }

    private function vehicles(): array
    {
        $stats = $this->stats();
        $visits = $this->visits()->groupBy('vehicle_id')->map->count();
        $missions = Mission::where('status', MissionStatus::Completed)->whereBetween('actual_end_at', [$this->from->utc(), $this->to->utc()])->selectRaw('vehicle_id, count(*) c')->groupBy('vehicle_id')->pluck('c', 'vehicle_id');
        $overspeed = GpsEvent::where('type', GpsEventType::Overspeed)->whereBetween('occurred_at', [$this->from->utc(), $this->to->utc()])->selectRaw('vehicle_id, count(*) c')->groupBy('vehicle_id')->pluck('c', 'vehicle_id');
        $rows = $this->perVehicle($stats)->map(fn ($r) => $r + ['visits' => (int) ($visits[$r['id']] ?? 0), 'missions' => (int) ($missions[$r['id']] ?? 0), 'overspeed' => (int) ($overspeed[$r['id']] ?? 0)])->sortByDesc('distance')->values();

        return [
            'summary' => ['Distance' => $this->km($stats->sum('distance_km')), 'Missions terminées' => (int) $missions->sum(), 'Alertes de survitesse' => (int) $overspeed->sum()],
            'chart' => ['type' => 'bar', 'labels' => $rows->pluck('name'), 'unit' => 'km', 'datasets' => [['label' => 'Distance (km)', 'data' => $rows->map(fn ($r) => round($r['distance']))]]],
            'columns' => $this->cols(['name' => 'Camion', 'driver' => 'Chauffeur principal', 'distance' => ['Distance', 'right', 'km'], 'driving' => ['Conduite', 'right', 'duration'], 'stopped' => ['Arrêt', 'right', 'duration'], 'visits' => ['Visites', 'right'], 'missions' => ['Missions', 'right'], 'overspeed' => ['Survitesses', 'right'], 'max_speed' => ['Vit. max', 'right', 'speed']]),
            'rows' => $rows->all(),
        ];
    }

    // ── Outils ─────────────────────────────────────────────────────────────────

    private function stats(): Collection
    {
        // Aujourd'hui n'est calculé que la nuit prochaine : on le calcule à la volée (au plus toutes les 5 min).
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        if ($this->to->gte($today)) {
            \Illuminate\Support\Facades\Cache::remember('reports:today-stats:'.$today->toDateString(), 300, fn () => app(\App\Services\Tracking\DailyStatsService::class)->compute($today) ?: 1);
        }

        return DailyVehicleStat::with('vehicle:id,name')->whereBetween('date', [$this->from->toDateString(), $this->to->toDateString()])
            ->when($this->vehicleIds, fn ($q) => $q->whereIn('vehicle_id', $this->vehicleIds))->get();
    }

    private function visits(?GeofenceType $type = null): Collection
    {
        return VehicleZoneVisit::whereNotNull('exited_at')->whereBetween('entered_at', [$this->from->utc(), $this->to->utc()])
            ->when($this->vehicleIds, fn ($q) => $q->whereIn('vehicle_id', $this->vehicleIds))
            ->when($type, fn ($q) => $q->whereHas('geofence', fn ($g) => $g->where('type', $type)))->get();
    }

    private function perVehicle(Collection $stats): Collection
    {
        $drivers = $this->mainDrivers();

        return $stats->groupBy('vehicle_id')->map(fn ($g, $id) => [
            'id' => $id, 'name' => $g->first()->vehicle?->name, 'driver' => $drivers[$id] ?? '—',
            'distance' => round($g->sum('distance_km'), 1), 'driving' => (int) $g->sum('driving_seconds'), 'stopped' => (int) $g->sum('stopped_seconds'),
            'stops' => (int) $g->sum('stops_count'), 'max_speed' => (int) $g->max('max_speed_kmh'),
            'driving_pct' => $this->pct($g->sum('driving_seconds'), $g->sum('driving_seconds') + $g->sum('stopped_seconds')),
        ])->sortBy('name')->values();
    }

    private function vehicleTable(Collection $stats): array
    {
        return [
            'columns' => $this->cols(['name' => 'Camion', 'driver' => 'Chauffeur principal', 'distance' => ['Distance', 'right', 'km'], 'driving' => ['Conduite', 'right', 'duration'], 'stopped' => ['Arrêt', 'right', 'duration'], 'driving_pct' => ['Conduite / total', 'right'], 'stops' => ['Arrêts', 'right'], 'max_speed' => ['Vit. max', 'right', 'speed']]),
            'rows' => $this->perVehicle($stats)->sortByDesc('distance')->values()->all(),
        ];
    }

    /** Chauffeur ayant fait le plus de services sur chaque camion pendant la période (à défaut, l'affecté). */
    private function mainDrivers(): array
    {
        $fromShifts = DriverShift::with('driver:id,first_name,last_name')->whereNotNull('driver_id')->whereBetween('start_at', [$this->from->utc(), $this->to->utc()])->get()
            ->groupBy('vehicle_id')->map(fn ($g) => $g->groupBy('driver_id')->sortByDesc(fn ($x) => $x->count())->first()->first()->driver?->full_name);
        $assigned = Vehicle::with('currentDriver:id,first_name,last_name')->get()->mapWithKeys(fn ($v) => [$v->id => $v->currentDriver?->full_name]);

        return $fromShifts->all() + $assigned->filter()->all();
    }

    /** Libellés des regroupements (jour / semaine / mois) sur la période. */
    private function buckets(): Collection
    {
        $out = collect();
        for ($d = $this->from; $d->lte($this->to); $d = $d->addDay()) {
            $out[$this->bucketKey($d)] ??= match ($this->group) {
                'week' => 'Sem. '.$d->startOfWeek()->format('d/m'),
                'month' => ucfirst($d->locale('fr')->isoFormat('MMMM YYYY')),
                default => $d->format('d/m'),
            };
        }

        return $out;
    }

    private function bucketKey(CarbonImmutable $d): string
    {
        return match ($this->group) { 'week' => $d->startOfWeek()->toDateString(), 'month' => $d->format('Y-m'), default => $d->toDateString() };
    }

    private function cols(array $def): array
    {
        return collect($def)->map(fn ($v, $k) => is_array($v) ? ['key' => $k, 'label' => $v[0], 'align' => $v[1] ?? 'left', 'format' => $v[2] ?? null] : ['key' => $k, 'label' => $v, 'align' => 'left', 'format' => null])->values()->all();
    }

    private function km(float $v): string { return number_format($v, 0, ',', ' ').' km'; }
    private function hours(int|float $s): string { return sprintf('%d h %02d', intdiv((int) $s, 3600), intdiv((int) $s % 3600, 60)); }
    private function pct(float $a, float $b): string { return $b > 0 ? round($a / $b * 100).' %' : '—'; }
}
