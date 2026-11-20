<?php

namespace App\Services\Tracking;

use App\Enums\GpsEventType;
use App\Enums\LiveStatus;
use App\Events\FleetActivity;
use App\Events\PositionUpdated;
use App\Models\GpsEvent;
use App\Models\Position;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Models\VehicleLiveStatus;
use App\Notifications\FleetAlert;
use App\Support\Geo;
use App\Support\Places;
use App\Support\Realtime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Point d'entrée de chaque position GPS : enregistrement, statut en direct,
 * détection des zones et des événements, diffusion temps réel.
 */
class PositionIngestor
{
    public const TZ = 'Africa/Casablanca';
    private const OVERSPEED_COOLDOWN_S = 600;

    public function __construct(private GeofenceDetector $zones, private Alerts $alerts) {}

    public function ingest(Vehicle $vehicle, float $lat, float $lng, float $speed, int $course, bool $ignition, CarbonInterface $at, bool $broadcast = true): VehicleLiveStatus
    {
        $speed = max(0, round($speed, 1));
        Position::create([
            'vehicle_id' => $vehicle->id, 'latitude' => $lat, 'longitude' => $lng,
            'speed_kmh' => $speed, 'course' => $course, 'ignition' => $ignition, 'recorded_at' => $at,
        ]);

        $live = $vehicle->liveStatus ?? new VehicleLiveStatus(['vehicle_id' => $vehicle->id, 'status' => LiveStatus::Offline]);
        $prevStatus = $live->status;
        $prevAt = $live->last_position_at;
        $prevIgnition = $live->ignition;

        // Kilométrage du jour (remis à zéro à minuit, heure de Casablanca).
        $sameDay = $prevAt && $prevAt->copy()->setTimezone(self::TZ)->isSameDay($at->copy()->setTimezone(self::TZ));
        $delta = ($sameDay && $live->latitude !== null) ? Geo::distanceM($live->latitude, $live->longitude, $lat, $lng) / 1000 : 0;
        $live->today_distance_km = ($sameDay ? $live->today_distance_km : 0) + $delta;

        $status = $speed > (float) Setting::get('thresholds.moving_kmh', 5) ? LiveStatus::Moving : LiveStatus::Stopped;
        if ($status !== $prevStatus) {
            $live->status_since = $at;
        }
        if ($prevStatus === LiveStatus::Offline && $prevAt) {
            $this->event(GpsEventType::Online, $vehicle, $at, ['offline_seconds' => $at->getTimestamp() - $prevAt->getTimestamp()]);
            if ($broadcast) {
                Realtime::send(new FleetActivity('online', "{$vehicle->name} : signal GPS retrouvé", $vehicle->id, [], $at->toIso8601String()));
            }
        }

        $zone = $this->zones->process($vehicle, $lat, $lng, $at, $vehicle->current_driver_id);

        $live->fill([
            'latitude' => $lat, 'longitude' => $lng, 'speed_kmh' => $speed, 'course' => $course, 'ignition' => $ignition,
            'status' => $status, 'geofence_id' => $zone?->id,
            // Adresse : la zone où se trouve le camion (même avant confirmation de la visite), sinon le lieu le plus proche.
            'address' => ($zone ?? $this->zones->zonesAt($lat, $lng)->first())?->name ?? Places::label($lat, $lng),
            'last_position_at' => $at,
        ])->save();
        $vehicle->setRelation('liveStatus', $live->setRelation('geofence', $zone));

        if ($prevAt !== null && $ignition !== $prevIgnition) {
            $this->event($ignition ? GpsEventType::IgnitionOn : GpsEventType::IgnitionOff, $vehicle, $at);
        }
        if ($speed > $vehicle->speed_limit_kmh) {
            $this->overspeed($vehicle, $speed, $lat, $lng, $at, $broadcast);
        }

        $vehicle->device?->forceFill(['last_seen_at' => $at])->saveQuietly();

        if ($broadcast) {
            Realtime::send(new PositionUpdated(LivePayload::make($vehicle)));
        }

        return $live;
    }

    private function overspeed(Vehicle $vehicle, float $speed, float $lat, float $lng, CarbonInterface $at, bool $broadcast): void
    {
        // Une alerte au plus toutes les 10 minutes par camion.
        if (! Cache::add("overspeed:{$vehicle->id}", 1, self::OVERSPEED_COOLDOWN_S)) {
            return;
        }
        $this->event(GpsEventType::Overspeed, $vehicle, $at, ['speed_kmh' => $speed, 'limit_kmh' => $vehicle->speed_limit_kmh, 'lat' => $lat, 'lng' => $lng]);
        if ($broadcast) {
            $msg = sprintf('%s roule à %d km/h (limite %d km/h).', $vehicle->name, $speed, $vehicle->speed_limit_kmh);
            Realtime::send(new FleetActivity('overspeed', $msg, $vehicle->id, ['speed_kmh' => $speed], $at->toIso8601String()));
            $this->alerts->send(new FleetAlert('overspeed', 'Excès de vitesse', $msg, 'warning', $vehicle->id, "/suivi?v={$vehicle->id}"));
        }
    }

    private function event(GpsEventType $type, Vehicle $vehicle, CarbonInterface $at, array $payload = []): void
    {
        GpsEvent::create(['type' => $type, 'vehicle_id' => $vehicle->id, 'driver_id' => $vehicle->current_driver_id, 'occurred_at' => $at, 'payload' => $payload ?: null]);
    }
}
