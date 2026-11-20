<?php

namespace App\Http\Controllers\Api;

use App\Enums\MissionStatus;
use App\Exports\TableExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mission\MissionRequest;
use App\Http\Resources\MissionResource;
use App\Models\Mission;
use App\Services\Fleet\MissionService;
use App\Services\Tracking\ShiftService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MissionController extends Controller
{
    private const TZ = 'Africa/Casablanca';
    private const WITH = ['vehicle:id,name,plate_number', 'driver:id,first_name,last_name,phone', 'customer:id,name,code', 'origin:id,name', 'destination:id,name', 'creator:id,name'];

    public function __construct(private MissionService $service) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Mission::class);
        $request->validate(['status' => ['nullable', 'in:all,pending,in_progress,completed,cancelled'], 'date' => ['nullable', 'date_format:Y-m-d'], 'per_page' => ['nullable', 'in:10,20,50']]);
        $page = $this->filtered($request)->paginate($request->integer('per_page', 20));

        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $next = Mission::where('status', MissionStatus::Pending)->where('planned_start_at', '>=', now())->min('planned_start_at');
        $doneToday = Mission::where('status', MissionStatus::Completed)->where('actual_end_at', '>=', $today->utc())->get(['actual_end_at', 'planned_end_at']);

        return MissionResource::collection($page)->additional(['meta' => [
            'counts' => Mission::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all() + ['all' => Mission::count()],
            'late' => Mission::where('status', MissionStatus::InProgress)->where('planned_end_at', '<', now())->count(),
            'next_pending_at' => $next ? CarbonImmutable::parse($next, 'UTC')->toIso8601String() : null,
            'completed_today' => $doneToday->count(),
            'completed_today_on_time' => $doneToday->filter(fn ($m) => $m->actual_end_at->lte($m->planned_end_at))->count(),
            'cancelled_month' => Mission::where('status', MissionStatus::Cancelled)->where('planned_start_at', '>=', $today->startOfMonth()->utc())->count(),
        ]])->response();
    }

    public function show(Mission $mission, ShiftService $shifts): MissionResource
    {
        Gate::authorize('view', $mission);
        $mission->load([...self::WITH, 'events.user:id,name']);
        if ($mission->status === MissionStatus::InProgress && $mission->actual_start_at) {
            $mission->distance_km = $shifts->distanceKm($mission->vehicle_id, $mission->actual_start_at, now());
        }

        return new MissionResource($mission);
    }

    public function store(MissionRequest $request): JsonResponse
    {
        $m = $this->service->create($request->validated(), $request->user());

        return (new MissionResource($m->load([...self::WITH, 'events.user:id,name'])))->response()->setStatusCode(201);
    }

    public function update(MissionRequest $request, Mission $mission): MissionResource
    {
        return new MissionResource($this->service->update($mission, $request->validated(), $request->user())->load([...self::WITH, 'events.user:id,name']));
    }

    public function destroy(Mission $mission): JsonResponse
    {
        Gate::authorize('delete', $mission);
        $mission->delete();

        return response()->json(['message' => "Mission {$mission->reference} supprimée."]);
    }

    /** Démarrer / Terminer / Annuler / Commenter. */
    public function transition(Request $request, Mission $mission, string $action): MissionResource
    {
        Gate::authorize('operate', $mission);
        $user = $request->user();
        match ($action) {
            'start' => $this->service->start($mission, $user),
            'complete' => $this->service->complete($mission, $user),
            'cancel' => $this->service->cancel($mission, $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [], ['reason' => 'motif'])['reason'], $user),
            'comment' => $this->service->comment($mission, $request->validate(['text' => ['required', 'string', 'max:500']], [], ['text' => 'commentaire'])['text'], $user),
        };

        return $this->show($mission->fresh(), app(ShiftService::class));
    }

    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('export', Mission::class);
        $f = fn ($d) => $d?->setTimezone(self::TZ)->format('d/m/Y H:i');
        $rows = $this->filtered($request)->get()->map(fn (Mission $m) => [
            $m->reference, $m->status->label(), $m->vehicle?->name, $m->driver?->full_name, $m->customer?->name, $m->origin?->name, $m->destination?->name,
            $f($m->planned_start_at), $f($m->planned_end_at), $f($m->actual_start_at), $f($m->actual_end_at), $m->distance_km, $m->cargo, $m->cancel_reason,
        ])->all();

        return Excel::download(new TableExport('Missions', ['Référence', 'Statut', 'Camion', 'Chauffeur', 'Client', 'Départ', 'Destination', 'Début prévu', 'Fin prévue', 'Début réel', 'Fin réelle', 'Km', 'Chargement', 'Motif d’annulation'], $rows),
            'missions-'.now(self::TZ)->format('Y-m-d').'.xlsx');
    }

    private function filtered(Request $request): Builder
    {
        $q = trim((string) $request->query('q'));
        $status = $request->query('status', 'all');

        return Mission::with(self::WITH)
            ->when($status !== 'all', fn ($b) => $b->where('status', $status))
            ->when($request->query('date'), function ($b, $d) {
                $day = CarbonImmutable::parse($d, self::TZ);
                $b->whereBetween('planned_start_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()]);
            })
            ->when($request->query('vehicle_id'), fn ($b, $v) => $b->where('vehicle_id', $v))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('reference', 'like', "%$q%")->orWhere('cargo', 'like', "%$q%")
                ->orWhereHas('vehicle', fn ($v) => $v->where('name', 'like', "%$q%"))
                ->orWhereHas('driver', fn ($d) => $d->where('first_name', 'like', "%$q%")->orWhere('last_name', 'like', "%$q%"))
                ->orWhereHas('destination', fn ($g) => $g->where('name', 'like', "%$q%"))))
            ->orderByRaw("FIELD(status, 'in_progress', 'pending', 'completed', 'cancelled')")
            ->orderByRaw("CASE WHEN status IN ('in_progress','pending') THEN planned_start_at END ASC")
            ->orderByDesc('planned_start_at');
    }
}
