<?php

namespace App\Http\Controllers\Api;

use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function show(Request $request, string $type): JsonResponse
    {
        Gate::authorize('reports.view');

        return response()->json(['data' => $this->build($request, $type)]);
    }

    public function export(Request $request, string $type): Response
    {
        Gate::authorize('reports.export');
        $request->validate(['format' => ['required', 'in:pdf,xlsx']]);
        $r = $this->build($request, $type);
        $name = 'rapport-'.str($r['title'])->slug().'-'.$r['from'].'-'.$r['to'];
        $cell = fn (array $row, array $c) => self::format($row[$c['key']] ?? null, $c['format']);

        if ($request->query('format') === 'xlsx') {
            return Excel::download(new TableExport(mb_substr($r['title'], 0, 31), array_column($r['columns'], 'label'),
                array_map(fn ($row) => array_map(fn ($c) => in_array($c['format'], ['duration'], true) ? $cell($row, $c) : ($row[$c['key']] ?? ''), $r['columns']), $r['rows'])), "$name.xlsx");
        }

        return Pdf::loadView('pdf.report', ['r' => $r, 'cell' => $cell, 'generatedAt' => now(ReportService::TZ)])->setPaper('a4', count($r['columns']) > 6 ? 'landscape' : 'portrait')->download("$name.pdf");
    }

    private function build(Request $request, string $type): array
    {
        abort_unless(isset(ReportService::TYPES[$type]), 404, 'Rapport inconnu.');
        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'vehicle_ids' => ['nullable', 'array'], 'vehicle_ids.*' => ['integer'], 'group' => ['nullable', 'in:day,week,month'],
        ]);
        $from = CarbonImmutable::parse($request->query('from'), ReportService::TZ);
        $to = CarbonImmutable::parse($request->query('to'), ReportService::TZ);
        abort_if($from->diffInDays($to) > 366, 422, 'La période ne peut pas dépasser un an.');

        return $this->reports->build($type, $from, $to, $request->query('vehicle_ids'), $request->query('group', 'day'));
    }

    public static function format(mixed $v, ?string $format): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        return match ($format) {
            'duration' => sprintf('%d h %02d', intdiv((int) $v, 3600), intdiv((int) $v % 3600, 60)),
            'km' => number_format((float) $v, 0, ',', ' ').' km',
            'number' => number_format((float) $v, 0, ',', ' '),
            'speed' => $v.' km/h',
            default => (string) $v,
        };
    }
}
