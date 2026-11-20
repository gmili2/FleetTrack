<?php

namespace App\Http\Controllers\Api;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\HistoryRequest;
use App\Models\Vehicle;
use App\Services\Tracking\HistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Historique des trajets et GPS Replay. */
class HistoryController extends Controller
{
    private const TZ = 'Africa/Casablanca';

    public function __construct(private HistoryService $history) {}

    public function show(HistoryRequest $request): JsonResponse
    {
        [$vehicle, $from, $to] = $this->period($request);

        return response()->json(['data' => [
            'vehicle' => ['id' => $vehicle->id, 'name' => $vehicle->name, 'plate_number' => $vehicle->plate_number, 'speed_limit_kmh' => $vehicle->speed_limit_kmh],
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ] + $this->history->build($vehicle, $from, $to)]);
    }

    /** Chronologie au format Excel. */
    public function export(HistoryRequest $request): BinaryFileResponse
    {
        Gate::authorize('reports.export');
        [$vehicle, $from, $to] = $this->period($request);
        $h = $this->history->build($vehicle, $from, $to);
        $t = fn (?string $iso) => $iso ? CarbonImmutable::parse($iso)->setTimezone(self::TZ)->format('H:i') : '';
        $dur = fn (?int $s) => $s === null ? '' : sprintf('%d h %02d', intdiv($s, 3600), intdiv($s % 3600, 60));
        $rows = array_map(fn ($it) => match ($it['kind']) {
            'departure' => ['A', 'Départ', $it['place'], $t($it['at']), '', '', '', '', ''],
            'arrival' => ['B', 'Arrivée', $it['place'], $t($it['at']), '', '', '', '', ''],
            'stop' => [$it['n'], 'Arrêt', $it['place'].($it['in_zone'] ? ' (zone)' : ''), $t($it['started_at']), $t($it['ended_at']), $dur($it['duration_seconds']), '', '', ''],
            default => ['', 'Trajet', '', $t($it['started_at']), $t($it['ended_at']), $dur($it['duration_seconds']), $it['distance_km'], $it['avg_speed_kmh'], $it['max_speed_kmh']],
        }, $h['timeline']);

        return Excel::download(new TableExport("{$vehicle->name} ".$from->setTimezone(self::TZ)->format('d-m-Y'),
            ['Repère', 'Type', 'Lieu', 'Début', 'Fin', 'Durée', 'Distance (km)', 'Vit. moy. (km/h)', 'Vit. max (km/h)'], $rows),
            'trajet-'.$vehicle->name.'-'.$from->setTimezone(self::TZ)->format('Y-m-d').'.xlsx');
    }

    /** @return array{0: Vehicle, 1: CarbonImmutable, 2: CarbonImmutable} */
    private function period(HistoryRequest $request): array
    {
        $vehicle = Vehicle::withTrashed()->findOrFail($request->integer('vehicle_id'));
        if ($request->filled('from') && $request->filled('to')) {
            $from = CarbonImmutable::parse($request->input('from'))->utc();
            $to = CarbonImmutable::parse($request->input('to'))->utc();
        } else {
            $day = CarbonImmutable::parse($request->input('date', now(self::TZ)->toDateString()), self::TZ);
            $from = $day->setTimeFromTimeString($request->input('start', '00:00'))->utc();
            $to = $day->setTimeFromTimeString($request->input('end', '23:59'))->addSeconds(59)->utc();
        }

        return [$vehicle, $from, $to];
    }
}
