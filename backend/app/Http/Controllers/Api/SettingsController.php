<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Position;
use App\Models\Setting;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Paramètres : seuils, état du simulateur, journal d'audit. */
class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize('settings.view');

        return response()->json(['data' => [
            'thresholds' => [
                'moving_kmh' => (int) Setting::get('thresholds.moving_kmh'),
                'offline_minutes' => (int) Setting::get('thresholds.offline_minutes'),
                'stop_minutes' => (int) Setting::get('thresholds.stop_minutes'),
            ],
            'simulator' => $this->simulatorStatus(),
        ]]);
    }

    public function thresholds(Request $request): JsonResponse
    {
        Gate::authorize('settings.update');
        $data = $request->validate([
            'moving_kmh' => ['required', 'integer', 'between:1,30'],
            'offline_minutes' => ['required', 'integer', 'between:2,180'],
            'stop_minutes' => ['required', 'integer', 'between:1,120'],
        ], [], ['moving_kmh' => 'seuil de mouvement', 'offline_minutes' => 'délai hors ligne', 'stop_minutes' => 'durée d’arrêt']);
        $before = collect(array_keys($data))->mapWithKeys(fn ($k) => [$k => Setting::get("thresholds.$k")])->all();
        foreach ($data as $k => $v) {
            Setting::put("thresholds.$k", $v);
        }
        AuditLog::record('settings', null, 'Seuils modifiés', ['before' => $before, 'after' => $data]);

        return response()->json(['message' => 'Seuils enregistrés.', 'data' => $data]);
    }

    /** Démarrer / mettre en pause le simulateur (le processus fleet:simulate lit ce réglage à chaque pas). */
    public function simulator(Request $request): JsonResponse
    {
        Gate::authorize('simulator.manage');
        $running = $request->validate(['running' => ['required', 'boolean']])['running'];
        Setting::put('simulator.running', $running);
        AuditLog::record('settings', null, $running ? 'Simulateur démarré' : 'Simulateur mis en pause');

        return response()->json(['message' => $running ? 'Simulateur démarré.' : 'Simulateur en pause.', 'data' => $this->simulatorStatus()]);
    }

    private function simulatorStatus(): array
    {
        $hb = Setting::get('simulator.heartbeat_at');

        return [
                'running' => (bool) Setting::get('simulator.running', false),
                'process_alive' => $hb !== null && now()->diffInSeconds(\Carbon\Carbon::parse($hb), true) < 30,
                'heartbeat_at' => $hb,
                'interval_seconds' => (int) Setting::get('simulator.interval_seconds', 5),
                'vehicles' => Vehicle::where('status', 'active')->count(),
            'positions_last_hour' => Position::where('recorded_at', '>=', now()->subHour())->count(),
        ];
    }

    public function audit(Request $request): JsonResponse
    {
        Gate::authorize('settings.view');
        $page = AuditLog::with('user:id,name')->latest('id')->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (AuditLog $a) => [
                'id' => $a->id, 'action' => $a->action, 'description' => $a->description, 'user' => $a->user?->name ?? 'Système',
                'subject' => $a->auditable_type ? class_basename($a->auditable_type).' #'.$a->auditable_id : null, 'ip' => $a->ip_address,
                'created_at' => $a->created_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }
}
