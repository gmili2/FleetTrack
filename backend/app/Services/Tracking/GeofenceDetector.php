<?php

namespace App\Services\Tracking;

use App\Enums\GeofenceShape;
use App\Enums\GeofenceType;
use App\Enums\GpsEventType;
use App\Enums\MissionEventType;
use App\Enums\MissionStatus;
use App\Events\FleetActivity;
use App\Models\Geofence;
use App\Models\GpsEvent;
use App\Models\Mission;
use App\Models\Vehicle;
use App\Models\VehicleZoneVisit;
use App\Notifications\FleetAlert;
use App\Support\Geo;
use App\Support\Realtime;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Détection des entrées / sorties de zones.
 *
 * Anti-rebond : une entrée n'est confirmée (visite créée) que si le camion reste dans la zone
 * au moins `min_visit_seconds` ; un simple passage n'est donc jamais compté. La visite prend
 * l'heure du premier point dans la zone. En sortie, une marge de 25 m évite les allers-retours
 * dus à l'imprécision GPS sur le bord d'un cercle.
 */
class GeofenceDetector
{
    private const EXIT_MARGIN_M = 25;

    /** Premières détections non encore confirmées : [vehicleId][geofenceId] => timestamp */
    private array $pending = [];
    private ?Collection $zones = null;
    private int $zonesLoadedAt = 0;

    public function __construct(private Alerts $alerts, public bool $notify = true) {}

    /** @return Collection<int, Geofence> */
    public function zones(): Collection
    {
        if ($this->zones === null || time() - $this->zonesLoadedAt > 60) {
            $this->zones = Geofence::active()->get();
            $this->zonesLoadedAt = time();
        }

        return $this->zones;
    }

    public function refreshZones(): void
    {
        $this->zones = null;
    }

    /** Zones contenant le point (sans anti-rebond). @return Collection<int, Geofence> */
    public function zonesAt(float $lat, float $lng): Collection
    {
        return $this->zones()->filter(fn (Geofence $z) => $z->contains($lat, $lng))->values();
    }

    /**
     * Traite une position ; ouvre / ferme les visites. Renvoie la zone où se trouve le camion (visite confirmée).
     */
    public function process(Vehicle $vehicle, float $lat, float $lng, CarbonInterface $at, ?int $driverId = null): ?Geofence
    {
        $open = VehicleZoneVisit::where('vehicle_id', $vehicle->id)->whereNull('exited_at')->get()->keyBy('geofence_id');
        $inside = $this->zones()->filter(fn (Geofence $z) => $open->has($z->id) ? $this->containsWithMargin($z, $lat, $lng) : $z->contains($lat, $lng))->keyBy('id');

        // Sorties
        foreach ($open as $zoneId => $visit) {
            if (! $inside->has($zoneId)) {
                $visit->close($at);
                $zone = $this->zones()->firstWhere('id', $zoneId) ?? Geofence::withTrashed()->find($zoneId);
                $this->onExit($vehicle, $zone, $visit, $at);
                $open->forget($zoneId);
            }
        }

        // Entrées (confirmées après min_visit_seconds)
        foreach ($this->pending[$vehicle->id] ?? [] as $zoneId => $ts) {
            if (! $inside->has($zoneId)) {
                unset($this->pending[$vehicle->id][$zoneId]);
            }
        }
        foreach ($inside as $zoneId => $zone) {
            if ($open->has($zoneId)) {
                continue;
            }
            $first = $this->pending[$vehicle->id][$zoneId] ??= $at->getTimestamp();
            if ($at->getTimestamp() - $first >= $zone->min_visit_seconds) {
                unset($this->pending[$vehicle->id][$zoneId]);
                $enteredAt = $at->copy()->setTimestamp($first);
                $visit = VehicleZoneVisit::create([
                    'vehicle_id' => $vehicle->id, 'geofence_id' => $zoneId, 'driver_id' => $driverId, 'entered_at' => $enteredAt,
                ]);
                $open->put($zoneId, $visit);
                $this->onEnter($vehicle, $zone, $enteredAt);
            }
        }

        $current = $open->keys()->map(fn ($id) => $inside->get($id))->filter();

        // Zone « principale » : la plus spécifique (client, chantier…) plutôt que le dépôt.
        return $current->sortBy(fn (Geofence $z) => $z->type === GeofenceType::Depot ? 1 : 0)->first();
    }

    /** Réinitialise l'état mémoire (camion relancé, tests). */
    public function forget(int $vehicleId): void
    {
        unset($this->pending[$vehicleId]);
    }

    private function containsWithMargin(Geofence $z, float $lat, float $lng): bool
    {
        if ($z->shape === GeofenceShape::Circle) {
            return Geo::inCircle($lat, $lng, $z->center_lat, $z->center_lng, $z->radius_m + self::EXIT_MARGIN_M);
        }

        return $z->contains($lat, $lng);
    }

    private function onEnter(Vehicle $vehicle, Geofence $zone, CarbonInterface $at): void
    {
        GpsEvent::create(['type' => GpsEventType::ZoneEnter, 'vehicle_id' => $vehicle->id, 'geofence_id' => $zone->id, 'driver_id' => $vehicle->current_driver_id, 'occurred_at' => $at]);
        $this->missionLog($vehicle, $zone, MissionEventType::ZoneEnter, "Entrée dans la zone « {$zone->name} »", $at);
        if (! $this->notify) {
            return;
        }
        Realtime::send(new FleetActivity('zone_enter', "{$vehicle->name} est entré dans « {$zone->name} »", $vehicle->id, ['geofence_id' => $zone->id, 'zone' => $zone->name], $at->toIso8601String()));
        if ($zone->type !== GeofenceType::Depot) {
            $this->alerts->send(new FleetAlert('zone_enter', 'Entrée en zone', "{$vehicle->name} est arrivé à « {$zone->name} ».", 'info', $vehicle->id, "/zones/{$zone->id}"));
        }
    }

    private function onExit(Vehicle $vehicle, ?Geofence $zone, VehicleZoneVisit $visit, CarbonInterface $at): void
    {
        GpsEvent::create(['type' => GpsEventType::ZoneExit, 'vehicle_id' => $vehicle->id, 'geofence_id' => $zone?->id, 'driver_id' => $vehicle->current_driver_id, 'occurred_at' => $at, 'payload' => ['duration_seconds' => $visit->duration_seconds]]);
        if (! $zone) {
            return;
        }
        $this->missionLog($vehicle, $zone, MissionEventType::ZoneExit, "Sortie de la zone « {$zone->name} »", $at);
        if (! $this->notify) {
            return;
        }
        $minutes = intdiv($visit->duration_seconds, 60);
        Realtime::send(new FleetActivity('zone_exit', "{$vehicle->name} a quitté « {$zone->name} » après $minutes min", $vehicle->id, ['geofence_id' => $zone->id, 'zone' => $zone->name, 'duration_seconds' => $visit->duration_seconds], $at->toIso8601String()));
        if ($zone->type !== GeofenceType::Depot) {
            $this->alerts->send(new FleetAlert('zone_exit', 'Sortie de zone', "{$vehicle->name} a quitté « {$zone->name} » après $minutes min sur place.", 'info', $vehicle->id, "/zones/{$zone->id}"));
        }
    }

    /** Journal automatique de la mission en cours si la zone en est le départ ou la destination. */
    private function missionLog(Vehicle $vehicle, Geofence $zone, MissionEventType $type, string $message, CarbonInterface $at): void
    {
        $mission = Mission::where('vehicle_id', $vehicle->id)->where('status', MissionStatus::InProgress)->first();
        if ($mission && in_array($zone->id, [$mission->origin_geofence_id, $mission->destination_geofence_id], true)) {
            $mission->events()->create(['type' => $type, 'message' => $message, 'occurred_at' => $at, 'payload' => ['geofence_id' => $zone->id]]);
        }
    }
}
