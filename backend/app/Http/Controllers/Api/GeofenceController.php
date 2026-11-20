<?php

namespace App\Http\Controllers\Api;

use App\Enums\GeofenceShape;
use App\Http\Controllers\Controller;
use App\Http\Requests\Geofence\GeofenceRequest;
use App\Http\Resources\GeofenceResource;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Geofence;
use App\Models\VehicleZoneVisit;
use App\Services\Tracking\GeofenceDetector;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Zones géographiques (dépôts, usines, clients, chantiers, zones de livraison). */
class GeofenceController extends Controller
{
    public function __construct(private GeofenceDetector $detector) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Geofence::class);
        $today = now('Africa/Casablanca')->startOfDay()->utc();
        $zones = Geofence::with('customer')
            ->withCount(['visits as visits_today' => fn ($q) => $q->where('entered_at', '>=', $today)])
            ->withCount(['visits as vehicles_inside' => fn ($q) => $q->whereNull('exited_at')])
            ->when($request->query('q'), fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->orderByRaw("FIELD(type, 'depot', 'factory', 'customer', 'site', 'delivery_zone')")->orderBy('name')->get();

        return GeofenceResource::collection($zones)->additional(['meta' => [
            'counts' => $zones->countBy(fn ($z) => $z->type->value)->all() + ['all' => $zones->count()],
            'visits_today' => (int) $zones->sum('visits_today'),
        ]])->response();
    }

    public function store(GeofenceRequest $request): JsonResponse
    {
        $zone = Geofence::create($this->payload($request));
        $this->detector->refreshZones();
        AuditLog::record('created', $zone, "Zone créée : {$zone->name}");

        return (new GeofenceResource($zone->load('customer')))->response()->setStatusCode(201);
    }

    public function show(Geofence $geofence): GeofenceResource
    {
        Gate::authorize('view', $geofence);

        return new GeofenceResource($geofence->load('customer'));
    }

    public function update(GeofenceRequest $request, Geofence $geofence): GeofenceResource
    {
        $geofence->update($this->payload($request));
        $this->detector->refreshZones();
        AuditLog::record('updated', $geofence, "Zone modifiée : {$geofence->name}");

        return new GeofenceResource($geofence->load('customer'));
    }

    public function destroy(Geofence $geofence): JsonResponse
    {
        Gate::authorize('delete', $geofence);
        // Les visites en cours sont clôturées ; l'historique est conservé.
        VehicleZoneVisit::where('geofence_id', $geofence->id)->whereNull('exited_at')->get()->each->close(now());
        $geofence->delete();
        $this->detector->refreshZones();
        AuditLog::record('deleted', $geofence, "Zone supprimée : {$geofence->name}");

        return response()->json(['message' => "Zone « {$geofence->name} » supprimée."]);
    }

    /** Clients pour le formulaire de zone. */
    public function customers(): JsonResponse
    {
        Gate::authorize('viewAny', Geofence::class);

        return response()->json(['data' => Customer::orderBy('name')->get(['id', 'code', 'name'])]);
    }

    private function payload(GeofenceRequest $request): array
    {
        $d = $request->validated();
        if ($d['shape'] === GeofenceShape::Polygon->value) {
            $coords = array_map(fn ($p) => [round((float) $p[0], 6), round((float) $p[1], 6)], $d['coordinates']);
            [$lat, $lng] = Geo::centroid($coords);
            [$d['center_lat'], $d['center_lng']] = [round($lat, 6), round($lng, 6)];
            $d['coordinates'] = $coords;
            $d['radius_m'] = null;
        } else {
            $d['coordinates'] = null;
        }
        $d['is_active'] = $d['is_active'] ?? true;

        return $d;
    }
}
