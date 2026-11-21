<?php

namespace App\Http\Controllers\Api;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Models\BadgeEvent;
use App\Models\DriverShift;
use App\Services\Tracking\ShiftPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Pointages : prises et fins de service par badge, anomalies, flux des badges. */
class ShiftController extends Controller
{
    private const TZ = 'Africa/Casablanca';

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('shifts.view');
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'tab' => ['nullable', 'in:all,open,closed,anomalies'], 'per_page' => ['nullable', 'in:10,20,50']]);
        $base = $this->dayQuery($request);
        $page = $this->filtered($request, clone $base)->paginate($request->integer('per_page', 20));
        $closed = (clone $base)->whereNotNull('end_at');

        return response()->json([
            'data' => collect($page->items())->map(fn ($s) => ShiftPresenter::row($s)),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'counts' => [
                    'all' => (clone $base)->count(),
                    'open' => (clone $base)->whereNull('end_at')->count(),
                    'closed' => (clone $closed)->count(),
                    'anomalies' => (clone $base)->whereNotNull('anomaly')->count(),
                ],
                'avg_duration_seconds' => (int) (clone $closed)->avg('duration_seconds'),
                'total_km' => round((float) (clone $closed)->sum('distance_km'), 1),
            ],
        ]);
    }

    /** Derniers passages de badge (flux « en direct », complété ensuite par Reverb). */
    public function badges(): JsonResponse
    {
        Gate::authorize('shifts.view');

        return response()->json(['data' => BadgeEvent::with('driver:id,first_name,last_name', 'vehicle:id,name', 'shift:id,anomaly')->latest('occurred_at')->limit(20)->get()
            ->map(fn (BadgeEvent $b) => [
                'id' => $b->id, 'type' => $b->type->value, 'source' => $b->source, 'badge' => $b->badge_number, 'driver' => $b->driver?->full_name,
                'vehicle' => $b->vehicle?->name, 'place' => $b->place, 'anomaly' => $b->shift?->anomaly?->value, 'at' => $b->occurred_at->toIso8601String(),
            ])]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('shifts.export');
        $fmt = fn (?string $iso) => $iso ? CarbonImmutable::parse($iso)->setTimezone(self::TZ)->format('d/m/Y H:i') : '';
        $rows = $this->filtered($request, $this->dayQuery($request))->get()->map(fn ($s) => ShiftPresenter::row($s))->map(fn ($r) => [
            $r['driver']['name'] ?? 'Badge inconnu', $r['driver']['employee_number'] ?? '', $r['badge_number'], $r['vehicle']['name'] ?? '', $r['vehicle']['plate_number'] ?? '',
            $fmt($r['start_at']), $r['start_place'], $r['open'] ? 'En service' : $fmt($r['end_at']), $r['end_place'],
            sprintf('%d h %02d', intdiv($r['duration_seconds'], 3600), intdiv($r['duration_seconds'] % 3600, 60)), $r['distance_km'],
            $r['anomaly_label'] ?? ($r['open'] ? 'En service' : 'Terminé'), $r['source'] === 'app' ? 'Application' : 'Badge',
        ])->all();
        $day = $request->query('date', now(self::TZ)->toDateString());

        return Excel::download(new TableExport("Pointages $day", ['Chauffeur', 'Matricule', 'Badge', 'Camion', 'Immatriculation', 'Entrée', 'Lieu d’entrée', 'Sortie', 'Lieu de sortie', 'Durée', 'Km', 'État', 'Source'], $rows), "pointages-$day.xlsx");
    }

    /** Services du jour + services encore ouverts commencés avant (ex. sortie non pointée la veille). */
    private function dayQuery(Request $request): Builder
    {
        $day = CarbonImmutable::parse($request->query('date', now(self::TZ)->toDateString()), self::TZ);
        [$from, $to] = [$day->startOfDay()->utc(), $day->endOfDay()->utc()];
        $isToday = $day->isSameDay(now(self::TZ));

        return DriverShift::with('driver', 'vehicle', 'gpsDevice')
            ->where(fn ($q) => $q->whereBetween('start_at', [$from, $to])->when($isToday, fn ($q) => $q->orWhereNull('end_at')));
    }

    private function filtered(Request $request, Builder $q): Builder
    {
        $s = trim((string) $request->query('q'));

        return $q->when($request->query('tab') === 'open', fn ($q) => $q->whereNull('end_at'))
            ->when($request->query('tab') === 'closed', fn ($q) => $q->whereNotNull('end_at'))
            ->when($request->query('tab') === 'anomalies', fn ($q) => $q->whereNotNull('anomaly'))
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('badge_number', 'like', "%$s%")
                ->orWhereHas('driver', fn ($d) => $d->where('first_name', 'like', "%$s%")->orWhere('last_name', 'like', "%$s%")->orWhere('employee_number', 'like', "%$s%"))
                ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'like', "%$s%")->orWhere('plate_number', 'like', "%$s%"))))
            ->orderByRaw('anomaly is null')->latest('start_at');
    }
}
