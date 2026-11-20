<?php

namespace App\Http\Controllers\Api;

use App\Enums\MissionStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Models\GpsEvent;
use App\Models\Mission;
use App\Models\Position;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Services\Tracking\LivePayload;
use App\Services\Tracking\TripAnalyzer;
use Illuminate\Http\JsonResponse;

/** Suivi en temps réel : état instantané de la flotte (complété ensuite par Reverb). */
class LiveController extends Controller
{
    public function index(): JsonResponse
    {
        $missions = Mission::with(['origin:id,name', 'destination:id,name'])
            ->where('status', MissionStatus::InProgress)->get()->keyBy('vehicle_id');
        $vehicles = Vehicle::with(LivePayload::WITH)->where('status', '!=', VehicleStatus::Inactive)->orderBy('name')->get();

        return response()->json([
            'data' => $vehicles->map(fn (Vehicle $v) => LivePayload::make($v, $missions->get($v->id), false))->values(),
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'simulator' => [
                    'running' => (bool) Setting::get('simulator.running', false),
                    'heartbeat_at' => Setting::get('simulator.heartbeat_at'),
                ],
            ],
        ]);
    }

    /** Détail d'un camion : état, statistiques du jour et derniers événements. */
    public function show(Vehicle $vehicle, TripAnalyzer $analyzer): JsonResponse
    {
        $vehicle->load(LivePayload::WITH);
        $from = now('Africa/Casablanca')->startOfDay()->utc();
        $positions = Position::where('vehicle_id', $vehicle->id)->where('recorded_at', '>=', $from)->orderBy('recorded_at')
            ->get(['latitude', 'longitude', 'speed_kmh', 'recorded_at']);
        $today = $analyzer->summarize($positions);
        unset($today['stops']);

        $events = GpsEvent::with('geofence:id,name')->where('vehicle_id', $vehicle->id)->latest('occurred_at')->limit(8)->get()
            ->map(fn (GpsEvent $e) => [
                'type' => $e->type->value, 'label' => $e->type->label(), 'tone' => $e->type->tone(),
                'zone' => $e->geofence?->name, 'payload' => $e->payload, 'occurred_at' => $e->occurred_at->toIso8601String(),
            ]);

        // Trace des 30 dernières minutes pour la « queue » du marqueur.
        $trail = $positions->where('recorded_at', '>=', now()->subMinutes(30))->map(fn ($p) => [$p->latitude, $p->longitude])->values();

        return response()->json(['data' => LivePayload::make($vehicle) + [
            'today' => $today + ['distance_km' => round($vehicle->liveStatus?->today_distance_km ?? $today['distance_km'], 1)],
            'events' => $events,
            'trail' => $trail,
        ]]);
    }

    /** Zones à afficher sur la carte. */
    public function geofences(): JsonResponse
    {
        return response()->json(['data' => Geofence::active()->orderBy('name')->get()->map(fn (Geofence $z) => [
            'id' => $z->id, 'name' => $z->name, 'type' => $z->type->value, 'type_label' => $z->type->label(),
            'shape' => $z->shape->value, 'center' => [$z->center_lat, $z->center_lng], 'radius_m' => $z->radius_m,
            'coordinates' => $z->coordinates, 'color' => $z->color,
        ])]);
    }
}
