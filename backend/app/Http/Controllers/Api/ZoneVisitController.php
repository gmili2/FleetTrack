<?php

namespace App\Http\Controllers\Api;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Services\Tracking\ZoneVisitReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/** Visites et durées dans une zone. */
class ZoneVisitController extends Controller
{
    public function __construct(private ZoneVisitReport $report) {}

    public function index(Request $request, Geofence $geofence): JsonResponse
    {
        Gate::authorize('view', $geofence);
        [$from, $to, $vehicleId] = $this->filters($request);
        $all = $this->report->query($geofence, $from, $to, $vehicleId)->get();
        $page = max(1, $request->integer('page', 1));
        $perPage = 15;

        return response()->json([
            'zone' => [
                'id' => $geofence->id, 'name' => $geofence->name, 'type' => $geofence->type->value, 'type_label' => $geofence->type->label(),
                'shape' => $geofence->shape->value, 'shape_label' => $geofence->shape->label(), 'radius_m' => $geofence->radius_m, 'address' => $geofence->address,
                'customer' => $geofence->customer ? ['code' => $geofence->customer->code, 'name' => $geofence->customer->name] : null,
                'min_visit_seconds' => $geofence->min_visit_seconds, 'center' => [$geofence->center_lat, $geofence->center_lng],
                'coordinates' => $geofence->coordinates, 'color' => $geofence->color,
            ],
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'stats' => $this->report->stats($all),
            'data' => $all->forPage($page, $perPage)->values()->map(fn ($v) => $this->report->row($v, $geofence)),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($all->count() / $perPage)), 'per_page' => $perPage, 'total' => $all->count()],
        ]);
    }

    public function export(Request $request, Geofence $geofence): Response
    {
        Gate::authorize('export', $geofence);
        $request->validate(['format' => ['required', 'in:xlsx,pdf']]);
        [$from, $to, $vehicleId] = $this->filters($request);
        $visits = $this->report->query($geofence, $from, $to, $vehicleId)->get();
        $rows = $visits->map(fn ($v) => $this->report->row($v, $geofence));
        $tz = ZoneVisitReport::TZ;
        $fmt = fn (?string $iso) => $iso ? CarbonImmutable::parse($iso)->setTimezone($tz)->format('d/m/Y H:i') : 'En cours';
        $name = 'visites-'.str($geofence->name)->slug().'-'.$from->setTimezone($tz)->format('Y-m-d');

        if ($request->query('format') === 'xlsx') {
            return Excel::download(new TableExport('Visites', ['Camion', 'Immatriculation', 'Chauffeur', 'Entrée', 'Sortie', 'Durée', 'Mission'],
                $rows->map(fn ($r) => [$r['vehicle']['name'] ?? '', $r['vehicle']['plate_number'] ?? '', $r['driver'], $fmt($r['entered_at']), $fmt($r['exited_at']),
                    ZoneVisitReport::fmt($r['duration_seconds']), $r['mission']['reference'] ?? ''])->all()), "$name.xlsx");
        }

        return Pdf::loadView('pdf.zone-visits', [
            'zone' => $geofence, 'stats' => $this->report->stats($visits), 'rows' => $rows, 'fmt' => $fmt,
            'from' => $from->setTimezone($tz), 'to' => $to->setTimezone($tz), 'generatedAt' => now($tz),
        ])->setPaper('a4')->download("$name.pdf");
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: ?int} */
    private function filters(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'vehicle_id' => ['nullable', 'integer']]);
        $tz = ZoneVisitReport::TZ;
        $from = CarbonImmutable::parse($request->query('from', now($tz)->subDays(6)->toDateString()), $tz)->startOfDay();
        $to = CarbonImmutable::parse($request->query('to', now($tz)->toDateString()), $tz)->endOfDay();

        return [$from->utc(), $to->utc(), $request->integer('vehicle_id') ?: null];
    }
}
