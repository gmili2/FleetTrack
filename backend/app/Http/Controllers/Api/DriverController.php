<?php

namespace App\Http\Controllers\Api;

use App\Enums\DriverStatus;
use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\Vehicle;
use App\Services\Tracking\ShiftPresenter;
use App\Services\Tracking\TripAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Chauffeurs (lecture) et leur historique de services. Le CRUD complet arrive à l'étape 4. */
class DriverController extends Controller
{
    public function index(): JsonResponse
    {
        $open = DriverShift::with('vehicle:id,name,plate_number')->whereNull('end_at')->get()->keyBy('driver_id');
        $monthStart = now('Africa/Casablanca')->startOfMonth()->utc();
        $counts = DriverShift::where('start_at', '>=', $monthStart)->selectRaw('driver_id, count(*) c')->groupBy('driver_id')->pluck('c', 'driver_id');
        $vehicles = Vehicle::whereNotNull('current_driver_id')->get(['id', 'name', 'plate_number', 'current_driver_id'])->keyBy('current_driver_id');

        $drivers = Driver::orderBy('last_name')->get()->map(function (Driver $d) use ($open, $counts, $vehicles) {
            $shift = $open->get($d->id);
            $vehicle = $shift?->vehicle ?? $vehicles->get($d->id);

            return [
                'id' => $d->id,
                'name' => $d->full_name,
                'initials' => mb_strtoupper(mb_substr($d->first_name, 0, 1).mb_substr($d->last_name, 0, 1)),
                'employee_number' => $d->employee_number,
                'badge_number' => $d->badge_number,
                'phone' => $d->phone,
                'license_number' => $d->license_number,
                'license_category' => $d->license_category,
                'license_expires_at' => $d->license_expires_at?->toDateString(),
                'license_expired' => $d->license_expires_at?->isPast() ?? false,
                'license_expires_soon' => $d->licenseExpiresSoon(),
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'status_tone' => $d->status->tone(),
                'vehicle' => $vehicle ? ['id' => $vehicle->id, 'name' => $vehicle->name, 'plate_number' => $vehicle->plate_number] : null,
                'on_shift' => $shift ? ['id' => $shift->id, 'start_at' => $shift->start_at->toIso8601String(), 'source' => $shift->source, 'vehicle_id' => $shift->vehicle_id] : null,
                'shifts_this_month' => (int) ($counts[$d->id] ?? 0),
            ];
        });

        return response()->json(['data' => $drivers, 'meta' => [
            'total' => $drivers->count(),
            'on_shift' => $drivers->whereNotNull('on_shift')->count(),
            'on_mission' => $drivers->where('status', DriverStatus::OnMission->value)->count(),
            'license_alerts' => $drivers->where('license_expires_soon', true)->count(),
        ]]);
    }

    /** Historique des services d'un chauffeur (paginé, filtrable par période). */
    public function shifts(Request $request, Driver $driver): JsonResponse
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $page = $driver->shifts()->with('vehicle', 'driver')
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
        return response()->json(['data' => ShiftPresenter::track($shift->load('vehicle', 'driver'), $analyzer)]);
    }
}
