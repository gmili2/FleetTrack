<?php

namespace App\Http\Controllers\Api;

use App\Enums\DriverStatus;
use App\Enums\MissionStatus;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Driver\DriverRequest;
use App\Http\Resources\DriverResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\Vehicle;
use App\Services\Tracking\ShiftPresenter;
use App\Services\Tracking\TripAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Chauffeurs : CRUD, export Excel et historique de leurs services. */
class DriverController extends Controller
{
    /** Liste paginée côté serveur (recherche, onglets par statut) avec compteurs. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Driver::class);
        $request->validate(['per_page' => ['nullable', 'integer', 'in:10,20,50,100'], 'tab' => ['nullable', 'in:all,on_shift,available,rest,inactive,on_mission']]);
        $page = $this->filtered($request)->paginate($request->integer('per_page', 20));
        $openDrivers = DriverShift::whereNull('end_at')->whereNotNull('driver_id')->pluck('driver_id');

        return DriverResource::collection($page)->additional(['meta' => ['counts' => [
            'all' => Driver::count(),
            'on_shift' => $openDrivers->unique()->count(),
            'available' => Driver::where('status', DriverStatus::Available)->whereNotIn('id', $openDrivers)->count(),
            'on_mission' => Driver::where('status', DriverStatus::OnMission)->count(),
            'rest' => Driver::where('status', DriverStatus::Rest)->count(),
            'inactive' => Driver::where('status', DriverStatus::Inactive)->count(),
            'license_alerts' => Driver::licenseExpiringWithin()->count(),
        ]]])->response();
    }

    public function store(DriverRequest $request): JsonResponse
    {
        $driver = Driver::create($request->validated());
        AuditLog::record('created', $driver, "Chauffeur créé : {$driver->full_name}");

        return (new DriverResource($driver))->response()->setStatusCode(201);
    }

    public function show(Driver $driver): DriverResource
    {
        Gate::authorize('view', $driver);

        return new DriverResource($driver->load('vehicle', 'openShift.vehicle'));
    }

    public function update(DriverRequest $request, Driver $driver): DriverResource
    {
        $before = $driver->only(['employee_number', 'badge_number', 'status', 'license_expires_at']);
        $driver->update($request->validated());
        AuditLog::record('updated', $driver, "Chauffeur modifié : {$driver->full_name}", ['before' => $before]);

        return new DriverResource($driver->load('vehicle', 'openShift.vehicle'));
    }

    public function destroy(Driver $driver): JsonResponse
    {
        Gate::authorize('delete', $driver);
        if ($driver->shifts()->whereNull('end_at')->exists()) {
            throw ValidationException::withMessages(['driver' => "{$driver->full_name} est en service : son service doit être clôturé avant la suppression."]);
        }
        if ($driver->missions()->where('status', MissionStatus::InProgress)->exists()) {
            throw ValidationException::withMessages(['driver' => "{$driver->full_name} a une mission en cours."]);
        }
        DB::transaction(function () use ($driver) {
            Vehicle::where('current_driver_id', $driver->id)->update(['current_driver_id' => null]);
            $driver->assignments()->whereNull('ended_at')->update(['ended_at' => now()]);
            User::where('driver_id', $driver->id)->update(['driver_id' => null]);
            $driver->delete();
            AuditLog::record('deleted', $driver, "Chauffeur supprimé : {$driver->full_name}");
        });

        return response()->json(['message' => "Chauffeur {$driver->full_name} supprimé."]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('export', Driver::class);
        $rows = $this->filtered($request)->get()->map(fn (Driver $d) => [
            $d->employee_number, $d->last_name, $d->first_name, $d->badge_number, $d->phone, $d->license_number, $d->license_category,
            $d->license_expires_at?->format('d/m/Y'), $d->status->label(), ($d->openShift?->vehicle ?? $d->vehicle)?->name,
            $d->openShift ? 'En service depuis '.$d->openShift->start_at->setTimezone('Africa/Casablanca')->format('d/m H:i') : '',
            (int) $d->shifts_this_month, $d->hired_at?->format('d/m/Y'),
        ])->all();

        return Excel::download(new TableExport('Chauffeurs', [
            'Matricule', 'Nom', 'Prénom', 'Badge', 'Téléphone', 'N° permis', 'Catégorie', 'Expiration permis', 'Statut', 'Camion', 'Service', 'Services (mois)', 'Embauche',
        ], $rows), 'chauffeurs-'.now('Africa/Casablanca')->format('Y-m-d').'.xlsx');
    }

    private function filtered(Request $request): Builder
    {
        $q = trim((string) $request->query('q'));
        $monthStart = now('Africa/Casablanca')->startOfMonth()->utc();

        return Driver::query()->with(['vehicle', 'openShift.vehicle'])
            ->withCount(['shifts as shifts_this_month' => fn ($s) => $s->where('start_at', '>=', $monthStart)])
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('first_name', 'like', "%$q%")->orWhere('last_name', 'like', "%$q%")
                ->orWhere('employee_number', 'like', "%$q%")->orWhere('badge_number', 'like', "%$q%")->orWhere('phone', 'like', "%$q%")
                ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'like', "%$q%"))))
            ->when($request->query('tab'), function ($b, $tab) {
                match ($tab) {
                    'on_shift' => $b->whereHas('shifts', fn ($s) => $s->whereNull('end_at')),
                    'available' => $b->where('status', DriverStatus::Available)->whereDoesntHave('shifts', fn ($s) => $s->whereNull('end_at')),
                    'all' => null,
                    default => $b->where('status', $tab),
                };
            })
            ->orderBy('last_name')->orderBy('first_name');
    }

    /** Historique des services d'un chauffeur (paginé, filtrable par période). */
    public function shifts(Request $request, Driver $driver): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $page = $driver->shifts()->with('vehicle', 'driver', 'gpsDevice')
            ->when($request->date('from'), fn ($q, $d) => $q->where('start_at', '>=', $d->copy()->shiftTimezone('Africa/Casablanca')->startOfDay()->utc()))
            ->when($request->date('to'), fn ($q, $d) => $q->where('start_at', '<=', $d->copy()->shiftTimezone('Africa/Casablanca')->endOfDay()->utc()))
            ->latest('start_at')->paginate(15);

        $closed = $driver->shifts()->whereNotNull('end_at');

        return response()->json([
            'driver' => ['id' => $driver->id, 'name' => $driver->full_name, 'employee_number' => $driver->employee_number, 'badge_number' => $driver->badge_number, 'phone' => $driver->phone],
            'data' => collect($page->items())->map(fn ($s) => ShiftPresenter::row($s)),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'stats' => [
                    'shifts' => (clone $closed)->count(),
                    'total_seconds' => (int) (clone $closed)->sum('duration_seconds'),
                    'total_km' => round((float) (clone $closed)->sum('distance_km'), 1),
                    'anomalies' => $driver->shifts()->whereNotNull('anomaly')->count(),
                ],
            ],
        ]);
    }

    public function track(DriverShift $shift, TripAnalyzer $analyzer): JsonResponse
    {
        return response()->json(['data' => ShiftPresenter::track($shift->load('vehicle', 'driver', 'gpsDevice'), $analyzer)]);
    }
}
