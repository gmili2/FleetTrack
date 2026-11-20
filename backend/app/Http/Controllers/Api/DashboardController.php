<?php

namespace App\Http\Controllers\Api;

use App\Enums\GeofenceType;
use App\Enums\GpsEventType;
use App\Enums\LiveStatus;
use App\Enums\MissionEventType;
use App\Enums\MissionStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\DailyVehicleStat;
use App\Models\GpsEvent;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\Position;
use App\Models\Vehicle;
use App\Services\Tracking\TripAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** Tableau de bord : indicateurs, km sur 7 jours, top 5 du jour, dernières activités. */
class DashboardController extends Controller
{
    private const TZ = 'Africa/Casablanca';

    public function __invoke(TripAnalyzer $analyzer): JsonResponse
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $vehicles = Vehicle::with(['liveStatus.geofence', 'currentDriver', 'openShift.driver'])->where('status', '!=', VehicleStatus::Inactive)->get();
        $live = $vehicles->pluck('liveStatus')->filter();
        $count = fn (LiveStatus $s) => $live->where('status', $s)->count();
        $stoppedAtCustomer = $live->where('status', LiveStatus::Stopped)->filter(fn ($l) => $l->geofence?->type === GeofenceType::Customer)->count();
        $distanceToday = round($live->sum('today_distance_km'));
        $yesterday = (float) DailyVehicleStat::where('date', $today->subDay()->toDateString())->sum('distance_km');

        $inProgress = Mission::where('status', MissionStatus::InProgress)->get(['id', 'planned_end_at']);
        $pending = Mission::where('status', MissionStatus::Pending)->count();

        // Arrêts du jour (seuil « arrêt après 5 min ») : recalculés au plus toutes les 60 s.
        $stops = Cache::remember('dashboard:stops:'.$today->toDateString(), 60, function () use ($vehicles, $today, $analyzer) {
            $n = 0;
            $seconds = 0;
            foreach ($vehicles as $v) {
                $s = $analyzer->summarize(Position::where('vehicle_id', $v->id)->where('recorded_at', '>=', $today->utc())->orderBy('recorded_at')->get(['latitude', 'longitude', 'speed_kmh', 'recorded_at']));
                $n += $s['stops_count'];
                $seconds += array_sum(array_column($s['stops'], 'duration_seconds'));
            }

            return ['count' => $n, 'avg_seconds' => $n ? intdiv($seconds, $n) : 0];
        });

        // Distance des 7 derniers jours (statistiques nocturnes + aujourd'hui en direct).
        $days = collect(range(6, 1))->map(fn ($i) => $today->subDays($i));
        $byDay = DailyVehicleStat::whereBetween('date', [$days->first()->toDateString(), $days->last()->toDateString()])
            ->selectRaw('date, sum(distance_km) km')->groupBy('date')->pluck('km', 'date')->mapWithKeys(fn ($km, $d) => [substr($d, 0, 10) => $km]);
        $week = $days->map(fn ($d) => ['date' => $d->toDateString(), 'distance_km' => round((float) ($byDay[$d->toDateString()] ?? 0))])
            ->push(['date' => $today->toDateString(), 'distance_km' => $distanceToday]);

        $top = $vehicles->sortByDesc(fn ($v) => $v->liveStatus?->today_distance_km ?? 0)->take(5)->values()->map(fn (Vehicle $v) => [
            'id' => $v->id, 'name' => $v->name, 'driver' => ($v->openShift?->driver ?? $v->currentDriver)?->full_name,
            'distance_km' => round($v->liveStatus?->today_distance_km ?? 0), 'status' => $v->liveStatus?->status->value ?? 'offline',
        ]);

        return response()->json(['data' => [
            'kpis' => [
                'total' => $vehicles->count(),
                'active' => $vehicles->where('status', VehicleStatus::Active)->count(),
                'maintenance' => $vehicles->where('status', VehicleStatus::Maintenance)->count(),
                'moving' => $count(LiveStatus::Moving),
                'stopped' => $count(LiveStatus::Stopped),
                'stopped_at_customer' => $stoppedAtCustomer,
                'offline' => $count(LiveStatus::Offline),
                'missions_in_progress' => $inProgress->count(),
                'missions_late' => $inProgress->filter(fn ($m) => $m->planned_end_at->isPast())->count(),
                'missions_pending' => $pending,
                'stops_today' => $stops['count'],
                'stops_avg_seconds' => $stops['avg_seconds'],
                'distance_today_km' => $distanceToday,
                'distance_yesterday_km' => round($yesterday),
            ],
            'week' => $week,
            'top' => $top,
            'activities' => $this->activities(),
        ]]);
    }

    /** Dernières activités : zones, signal, survitesse, pointages, missions. */
    private function activities(): array
    {
        $types = [GpsEventType::ZoneEnter, GpsEventType::ZoneExit, GpsEventType::Offline, GpsEventType::Overspeed, GpsEventType::BadgeIn, GpsEventType::BadgeOut];
        $gps = GpsEvent::with('vehicle:id,name', 'geofence:id,name', 'driver:id,first_name,last_name')
            ->whereIn('type', $types)->latest('occurred_at')->limit(12)->get()->map(function (GpsEvent $e) {
                $v = $e->vehicle?->name ?? 'Camion';
                $zone = $e->geofence?->name;
                $p = $e->payload ?? [];
                [$kind, $text] = match ($e->type) {
                    GpsEventType::ZoneEnter => ['zone', "$v est entré dans la zone « $zone »"],
                    GpsEventType::ZoneExit => ['zone', "$v est sorti de « $zone »".(isset($p['duration_seconds']) ? ' après '.self::dur($p['duration_seconds']) : '')],
                    GpsEventType::Offline => ['offline', "$v hors ligne"],
                    GpsEventType::Overspeed => ['overspeed', sprintf('Survitesse : %s à %d km/h (limite %d)', $v, $p['speed_kmh'] ?? 0, $p['limit_kmh'] ?? 0)],
                    GpsEventType::BadgeIn => ['badge', ($e->driver?->full_name ?? 'Badge inconnu')." a pris son service sur $v"],
                    GpsEventType::BadgeOut => ['badge', ($e->driver?->full_name ?? 'Badge inconnu')." a terminé son service sur $v"],
                    default => ['info', $e->type->label()],
                };

                return ['kind' => $kind, 'text' => $text, 'detail' => $e->driver?->full_name, 'vehicle_id' => $e->vehicle_id, 'at' => $e->occurred_at->toIso8601String()];
            });

        $missions = MissionEvent::with('mission:id,reference,vehicle_id,driver_id', 'mission.vehicle:id,name', 'mission.driver:id,first_name,last_name')
            ->whereIn('type', [MissionEventType::Started, MissionEventType::Completed, MissionEventType::Cancelled])
            ->latest('occurred_at')->limit(6)->get()->map(fn (MissionEvent $e) => [
                'kind' => 'mission',
                'text' => "Mission {$e->mission?->reference} ".match ($e->type) { MissionEventType::Started => 'démarrée', MissionEventType::Completed => 'terminée', default => 'annulée' },
                'detail' => trim(($e->mission?->vehicle?->name ?? '').' · '.($e->mission?->driver?->full_name ?? ''), ' ·'),
                'vehicle_id' => $e->mission?->vehicle_id, 'at' => $e->occurred_at->toIso8601String(),
            ]);

        return $gps->concat($missions)->sortByDesc('at')->take(10)->values()->all();
    }

    private static function dur(int $s): string
    {
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);

        return $h ? sprintf('%d h %02d', $h, $m) : "$m min";
    }
}
