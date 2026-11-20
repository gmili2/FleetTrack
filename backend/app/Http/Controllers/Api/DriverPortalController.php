<?php

namespace App\Http\Controllers\Api;

use App\Enums\GeofenceType;
use App\Enums\LiveStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DriverPortal\ClockInRequest;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\Vehicle;
use App\Services\Tracking\GeofenceDetector;
use App\Services\Tracking\LivePayload;
use App\Services\Tracking\ShiftPresenter;
use App\Services\Tracking\ShiftService;
use App\Services\Tracking\TripAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Espace chauffeur : le chauffeur connecté pointe l'entrée en choisissant un camion,
 * puis la sortie. Le lieu du pointage est celui du boîtier GPS du camion.
 */
class DriverPortalController extends Controller
{
    public function __construct(private ShiftService $shifts, private GeofenceDetector $zones) {}

    /** Mon service : chauffeur, service en cours (avec le camion en direct), derniers services. */
    public function show(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $open = $driver->shifts()->with('vehicle', 'driver')->whereNull('end_at')->latest('start_at')->first();
        $vehicle = $open ? Vehicle::with(LivePayload::WITH)->find($open->vehicle_id) : null;

        return response()->json(['data' => [
            'driver' => [
                'id' => $driver->id, 'name' => $driver->full_name, 'employee_number' => $driver->employee_number,
                'badge_number' => $driver->badge_number, 'phone' => $driver->phone,
                'license_expires_at' => $driver->license_expires_at?->toDateString(), 'license_expires_soon' => $driver->licenseExpiresSoon(),
            ],
            'shift' => $open ? array_merge(ShiftPresenter::row($open), [
                'distance_km' => $this->shifts->distanceKm($open->vehicle_id, $open->start_at, now()),
            ]) : null,
            'vehicle' => $vehicle ? LivePayload::make($vehicle) : null,
            'recent' => $driver->shifts()->with('vehicle', 'driver')->whereNotNull('end_at')->latest('start_at')->limit(10)->get()->map(fn ($s) => ShiftPresenter::row($s)),
        ]]);
    }

    /** Camions que le chauffeur peut prendre : actifs, à l'arrêt, sans autre chauffeur pointé via l'application. */
    public function vehicles(Request $request): JsonResponse
    {
        $this->driver($request);
        $vehicles = Vehicle::with(LivePayload::WITH)->where('status', VehicleStatus::Active)->orderBy('name')->get()
            ->map(function (Vehicle $v) {
                $p = LivePayload::make($v, null, false);
                $busy = $v->openShift?->source === 'app';
                $moving = $v->liveStatus?->status === LiveStatus::Moving;
                $p['available'] = ! $busy && ! $moving;
                $p['unavailable_reason'] = $busy ? 'Déjà pris par '.($v->openShift->driver?->full_name ?? 'un autre chauffeur') : ($moving ? 'En route' : null);
                $l = $v->liveStatus;
                $p['at_depot'] = $l?->latitude !== null && $this->zones->zonesAt($l->latitude, $l->longitude)->contains(fn ($z) => $z->type === GeofenceType::Depot);

                return $p;
            })
            ->sortBy([fn ($a, $b) => $b['available'] <=> $a['available'], fn ($a, $b) => $b['at_depot'] <=> $a['at_depot']])->values();

        return response()->json(['data' => $vehicles]);
    }

    public function clockIn(ClockInRequest $request): JsonResponse
    {
        $driver = $this->driver($request);

        $shift = DB::transaction(function () use ($driver, $request) {
            if ($driver->shifts()->whereNull('end_at')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['vehicle_id' => 'Vous êtes déjà en service. Pointez d’abord votre sortie.']);
            }
            $vehicle = Vehicle::with('liveStatus.geofence', 'openShift.driver')->lockForUpdate()->findOrFail($request->integer('vehicle_id'));
            $live = $vehicle->liveStatus;
            if ($vehicle->status !== VehicleStatus::Active || ! $live?->latitude) {
                throw ValidationException::withMessages(['vehicle_id' => 'Ce camion n’est pas disponible (maintenance ou boîtier sans position).']);
            }
            if ($vehicle->openShift?->source === 'app') {
                throw ValidationException::withMessages(['vehicle_id' => "{$vehicle->name} est déjà pris par {$vehicle->openShift->driver?->full_name}."]);
            }
            if ($live->status === LiveStatus::Moving) {
                throw ValidationException::withMessages(['vehicle_id' => "{$vehicle->name} est en route : choisissez un camion à l’arrêt."]);
            }

            $now = CarbonImmutable::now();
            // Le chauffeur précédent (pointage par badge) est relevé : fin de son service.
            if ($prev = $vehicle->openShift) {
                $this->shifts->badgeOut($vehicle, $prev->badge_number ?? $prev->driver?->badge_number ?? '', $now, $live->latitude, $live->longitude);
            }

            return $this->shifts->badgeIn($vehicle, $driver->badge_number, $now, $live->latitude, $live->longitude, 'app');
        });

        return response()->json(['data' => ShiftPresenter::row($shift->load('vehicle', 'driver')), 'message' => "Prise de service enregistrée sur {$shift->vehicle->name}."], 201);
    }

    public function clockOut(Request $request): JsonResponse
    {
        $driver = $this->driver($request);
        $open = $driver->shifts()->with('vehicle.liveStatus')->whereNull('end_at')->latest('start_at')->first();
        if (! $open) {
            throw ValidationException::withMessages(['shift' => 'Aucun service en cours.']);
        }
        $live = $open->vehicle->liveStatus;
        $shift = $this->shifts->badgeOut($open->vehicle, $driver->badge_number, CarbonImmutable::now(), $live?->latitude ?? 0, $live?->longitude ?? 0, 'app');

        return response()->json(['data' => ShiftPresenter::row($shift->fresh(['vehicle', 'driver'])), 'message' => 'Fin de service enregistrée.']);
    }

    /** Trace d'un de mes services. */
    public function track(Request $request, DriverShift $shift, TripAnalyzer $analyzer): JsonResponse
    {
        abort_unless($shift->driver_id === $this->driver($request)->id, 403, 'Ce service ne vous appartient pas.');

        return response()->json(['data' => ShiftPresenter::track($shift->load('vehicle', 'driver'), $analyzer)]);
    }

    private function driver(Request $request): Driver
    {
        $driver = $request->user()->driver;
        abort_unless($driver !== null, 403, 'Votre compte n’est lié à aucun chauffeur. Contactez un administrateur.');

        return $driver;
    }
}
