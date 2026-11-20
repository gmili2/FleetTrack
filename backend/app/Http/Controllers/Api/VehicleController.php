<?php

namespace App\Http\Controllers\Api;

use App\Enums\GeofenceType;
use App\Enums\GpsEventType;
use App\Enums\LiveStatus;
use App\Enums\MissionStatus;
use App\Enums\VehicleStatus;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\VehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\DailyVehicleStat;
use App\Models\GpsEvent;
use App\Models\Mission;
use App\Models\Position;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleZoneVisit;
use App\Services\Fleet\VehicleService;
use App\Services\Tracking\TripAnalyzer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class VehicleController extends Controller
{
    private const WITH = ['currentDriver', 'device', 'liveStatus'];
    private const SORTS = ['name' => 'name', 'plate_number' => 'plate_number', 'type' => 'type', 'status' => 'status', 'today_km' => 'today_km'];

    public function __construct(private VehicleService $service) {}

    /** Liste paginée côté serveur, avec filtres, tri et compteurs. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Vehicle::class);
        $request->validate(['per_page' => ['nullable', 'integer', 'in:10,20,50,100'], 'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORTS))], 'dir' => ['nullable', 'in:asc,desc']]);

        $page = $this->filtered($request)->paginate($request->integer('per_page', 10));
        $counts = Vehicle::query()->leftJoin('vehicle_live_status as l', 'l.vehicle_id', '=', 'vehicles.id')
            ->selectRaw("count(*) as total, sum(vehicles.status = 'active') as active, sum(vehicles.status = 'maintenance') as maintenance,
                sum(l.status = 'moving') as moving, sum(l.status = 'stopped') as stopped, sum(l.status = 'offline' or l.status is null) as offline,
                sum(not exists(select 1 from gps_devices d where d.vehicle_id = vehicles.id)) as no_device")->first();

        return VehicleResource::collection($page)->additional(['meta' => ['counts' => array_map('intval', $counts->toArray())]])->response();
    }

    public function store(VehicleRequest $request): JsonResponse
    {
        return (new VehicleResource($this->service->save(null, $request->validated())))->response()->setStatusCode(201);
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        Gate::authorize('view', $vehicle);

        return new VehicleResource($vehicle->load(self::WITH));
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        return new VehicleResource($this->service->save($vehicle, $request->validated()));
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        Gate::authorize('delete', $vehicle);
        $this->service->delete($vehicle);

        return response()->json(['message' => "Camion {$vehicle->name} supprimé."]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('export', Vehicle::class);
        $rows = $this->filtered($request)->get()->map(fn (Vehicle $v) => [
            $v->plate_number, $v->name, $v->type->label(), trim("{$v->brand} {$v->model}"), $v->year, $v->status->label(),
            $v->currentDriver?->full_name, $v->device?->imei, $v->device?->model, $v->liveStatus?->status->label(),
            $v->liveStatus?->address, $v->liveStatus?->last_position_at?->setTimezone('Africa/Casablanca')->format('d/m/Y H:i'),
            round($v->liveStatus?->today_distance_km ?? 0, 1), $v->speed_limit_kmh, $v->odometer_km,
        ])->all();

        return Excel::download(new TableExport('Camions', [
            'Immatriculation', 'Nom', 'Type', 'Marque / modèle', 'Année', 'Statut', 'Chauffeur', 'IMEI', 'Boîtier', 'Statut GPS',
            'Dernière position', 'Heure', 'Km jour', 'Vitesse max (km/h)', 'Kilométrage',
        ], $rows), 'camions-'.now('Africa/Casablanca')->format('Y-m-d').'.xlsx');
    }

    /** Fiche camion : indicateurs des 30 derniers jours. */
    public function overview(Vehicle $vehicle): JsonResponse
    {
        Gate::authorize('view', $vehicle);
        $from = now('Africa/Casablanca')->subDays(30)->startOfDay();
        $stats = DailyVehicleStat::where('vehicle_id', $vehicle->id)->where('date', '>=', $from->toDateString())->get();
        $live = $vehicle->liveStatus;
        $activeDays = max(1, $stats->where('distance_km', '>', 0)->count());
        $visits = VehicleZoneVisit::where('vehicle_id', $vehicle->id)->where('entered_at', '>=', $from->utc())->whereNotNull('exited_at')->with('geofence:id,type')->get();
        $missions = Mission::where('vehicle_id', $vehicle->id)->where('planned_start_at', '>=', $from->utc())->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $distance = $stats->sum('distance_km') + ($live?->today_distance_km ?? 0);

        return response()->json(['data' => [
            'distance_km' => round($distance),
            'avg_km_per_day' => round($stats->sum('distance_km') / $activeDays),
            'driving_seconds' => (int) $stats->sum('driving_seconds'),
            'stopped_seconds' => (int) $stats->sum('stopped_seconds'),
            'stops_count' => (int) $stats->sum('stops_count'),
            'visits' => $visits->count(),
            'customer_visits' => $visits->filter(fn ($v) => $v->geofence?->type === GeofenceType::Customer)->count(),
            'missions' => (int) $missions->sum(),
            'missions_completed' => (int) ($missions[MissionStatus::Completed->value] ?? 0),
            'missions_cancelled' => (int) ($missions[MissionStatus::Cancelled->value] ?? 0),
            'max_speed_kmh' => (int) $stats->max('max_speed_kmh'),
            'overspeed_alerts' => GpsEvent::where('vehicle_id', $vehicle->id)->where('type', GpsEventType::Overspeed)->where('occurred_at', '>=', $from->utc())->count(),
            'daily' => $stats->sortBy('date')->values()->map(fn ($s) => ['date' => $s->date->toDateString(), 'distance_km' => $s->distance_km]),
        ]]);
    }

    /**
     * Trajets : d'une sortie de zone à l'entrée dans la zone suivante (30 derniers jours),
     * plus le trajet en cours si le camion roule hors zone.
     */
    public function trips(Request $request, Vehicle $vehicle, TripAnalyzer $analyzer): JsonResponse
    {
        Gate::authorize('view', $vehicle);
        $visits = VehicleZoneVisit::with('geofence:id,name')->where('vehicle_id', $vehicle->id)
            ->where('entered_at', '>=', now()->subDays(31))->orderBy('entered_at')->get();
        $trips = [];
        foreach ($visits as $i => $v) {
            if (! $v->exited_at) {
                continue;
            }
            $next = $visits[$i + 1] ?? null;
            if ($next && $next->entered_at->lte($v->exited_at)) {
                continue; // zones imbriquées
            }
            $trips[] = ['from' => $v->geofence?->name, 'to' => $next?->geofence?->name, 'start' => $v->exited_at, 'end' => $next?->entered_at];
        }
        $trips = array_reverse($trips);
        if ($trips && $trips[0]['end'] === null && $vehicle->liveStatus?->status === LiveStatus::Offline) {
            $trips[0]['end'] = $vehicle->liveStatus->last_position_at;
        }

        $perPage = 15;
        $pageNo = max(1, $request->integer('page', 1));
        $slice = array_slice($trips, ($pageNo - 1) * $perPage, $perPage);
        $data = array_map(function ($t) use ($vehicle, $analyzer) {
            $end = $t['end'] ?? now();
            $s = $analyzer->summarize(Position::where('vehicle_id', $vehicle->id)->whereBetween('recorded_at', [$t['start'], $end])->orderBy('recorded_at')->get(['latitude', 'longitude', 'speed_kmh', 'recorded_at']));

            return [
                'from' => $t['from'], 'to' => $t['to'], 'started_at' => $t['start']->toIso8601String(), 'ended_at' => $t['end']?->toIso8601String(),
                'ongoing' => $t['end'] === null, 'duration_seconds' => $end->getTimestamp() - $t['start']->getTimestamp(),
                'distance_km' => $s['distance_km'], 'avg_speed_kmh' => $s['avg_speed_kmh'], 'max_speed_kmh' => $s['max_speed_kmh'],
            ];
        }, $slice);

        return response()->json(['data' => $data, 'meta' => ['current_page' => $pageNo, 'last_page' => max(1, (int) ceil(count($trips) / $perPage)), 'total' => count($trips)]]);
    }

    public function visits(Vehicle $vehicle): JsonResponse
    {
        Gate::authorize('view', $vehicle);
        $page = VehicleZoneVisit::with('geofence:id,name,type', 'driver:id,first_name,last_name')->where('vehicle_id', $vehicle->id)->latest('entered_at')->paginate(15);

        return response()->json(['data' => collect($page->items())->map(fn ($v) => [
            'id' => $v->id, 'zone' => $v->geofence?->name, 'zone_type' => $v->geofence?->type->label(), 'driver' => $v->driver?->full_name,
            'entered_at' => $v->entered_at->toIso8601String(), 'exited_at' => $v->exited_at?->toIso8601String(),
            'duration_seconds' => $v->duration_seconds ?? (now()->getTimestamp() - $v->entered_at->getTimestamp()), 'ongoing' => $v->exited_at === null,
        ]), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function missions(Vehicle $vehicle): JsonResponse
    {
        Gate::authorize('view', $vehicle);
        $page = Mission::with('driver:id,first_name,last_name', 'origin:id,name', 'destination:id,name')->where('vehicle_id', $vehicle->id)->latest('planned_start_at')->paginate(15);

        return response()->json(['data' => collect($page->items())->map(fn (Mission $m) => [
            'id' => $m->id, 'reference' => $m->reference, 'driver' => $m->driver?->full_name, 'origin' => $m->origin?->name, 'destination' => $m->destination?->name,
            'planned_start_at' => $m->planned_start_at->toIso8601String(), 'actual_end_at' => $m->actual_end_at?->toIso8601String(),
            'status' => $m->status->value, 'status_label' => $m->status->label(), 'status_tone' => $m->status->tone(), 'distance_km' => $m->distance_km,
        ]), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function assignments(Vehicle $vehicle): JsonResponse
    {
        Gate::authorize('view', $vehicle);

        return response()->json(['data' => VehicleAssignment::with('driver')->where('vehicle_id', $vehicle->id)->latest('started_at')->get()->map(fn ($a) => [
            'id' => $a->id, 'driver' => $a->driver ? ['id' => $a->driver->id, 'name' => $a->driver->full_name, 'employee_number' => $a->driver->employee_number] : null,
            'started_at' => $a->started_at->toIso8601String(), 'ended_at' => $a->ended_at?->toIso8601String(),
        ])]);
    }

    private function filtered(Request $request): Builder
    {
        $q = trim((string) $request->query('q'));
        $sort = self::SORTS[$request->query('sort', 'name')] ?? 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        return Vehicle::query()->with(self::WITH)->select('vehicles.*')
            ->leftJoin('vehicle_live_status as l', 'l.vehicle_id', '=', 'vehicles.id')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('vehicles.name', 'like', "%$q%")->orWhere('plate_number', 'like', "%$q%")
                ->orWhere('brand', 'like', "%$q%")->orWhere('model', 'like', "%$q%")
                ->orWhereHas('currentDriver', fn ($d) => $d->where('first_name', 'like', "%$q%")->orWhere('last_name', 'like', "%$q%"))
                ->orWhereHas('device', fn ($d) => $d->where('imei', 'like', "%$q%"))))
            ->when($request->query('type'), fn ($b, $t) => $b->where('vehicles.type', $t))
            ->when($request->query('status'), fn ($b, $s) => $b->where('vehicles.status', $s))
            ->when($request->query('gps'), fn ($b, $g) => $g === 'no_device' ? $b->whereDoesntHave('device') : $b->where('l.status', $g))
            ->when($sort === 'today_km', fn ($b) => $b->orderBy('l.today_distance_km', $dir), fn ($b) => $b->orderBy("vehicles.$sort", $dir));
    }
}
