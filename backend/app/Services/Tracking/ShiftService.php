<?php

namespace App\Services\Tracking;

use App\Enums\BadgeType;
use App\Enums\GeofenceType;
use App\Enums\GpsEventType;
use App\Enums\LiveStatus;
use App\Enums\ShiftAnomaly;
use App\Events\FleetActivity;
use App\Models\BadgeEvent;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\GpsDevice;
use App\Models\GpsEvent;
use App\Models\Position;
use App\Models\Vehicle;
use App\Models\VehicleZoneVisit;
use App\Notifications\FleetAlert;
use App\Support\Geo;
use App\Support\Places;
use App\Support\Realtime;
use Carbon\CarbonInterface;

/**
 * Pointage par badge.
 * - Badge « in » : ouvre un service (chauffeur + camion + heure + lieu).
 * - Badge « out » : ferme le service, calcule la durée et la distance.
 * Anomalies : prise hors dépôt, badge inconnu, sortie non pointée.
 */
class ShiftService
{
    /** Délai après le retour au dépôt avant de signaler une sortie non pointée. */
    public const UNBADGED_AFTER_MINUTES = 15;

    public function __construct(private GeofenceDetector $zones, private Alerts $alerts, public bool $notify = true) {}

    /** @param string $source badge (lecteur du camion) | app (application chauffeur) */
    public function badgeIn(Vehicle $vehicle, string $badge, CarbonInterface $at, float $lat, float $lng, string $source = 'badge'): DriverShift
    {
        $driver = Driver::where('badge_number', $badge)->first();
        $zone = $this->zones->zonesAt($lat, $lng)->sortBy(fn ($z) => $z->type === GeofenceType::Depot ? 0 : 1)->first();
        $place = $zone?->name ?? Places::label($lat, $lng);

        // Un service resté ouvert (badge de sortie oublié) est clôturé.
        DriverShift::whereNull('end_at')
            ->where(fn ($q) => $q->where('vehicle_id', $vehicle->id)->when($driver, fn ($q) => $q->orWhere('driver_id', $driver->id)))
            ->get()->each(fn (DriverShift $s) => $this->closeUnbadged($s, $at));

        $anomaly = match (true) {
            $driver === null => ShiftAnomaly::UnknownBadge,
            $zone?->type !== GeofenceType::Depot => ShiftAnomaly::OutsideDepot,
            default => null,
        };

        $shift = DriverShift::create([
            'driver_id' => $driver?->id, 'vehicle_id' => $vehicle->id, 'gps_device_id' => GpsDevice::where('vehicle_id', $vehicle->id)->value('id'),
            'badge_number' => $badge, 'source' => $source,
            'start_at' => $at, 'start_lat' => $lat, 'start_lng' => $lng, 'start_place' => $place, 'anomaly' => $anomaly,
        ]);
        BadgeEvent::create([
            'badge_number' => $badge, 'driver_id' => $driver?->id, 'vehicle_id' => $vehicle->id, 'driver_shift_id' => $shift->id,
            'type' => BadgeType::In, 'source' => $source, 'occurred_at' => $at, 'latitude' => $lat, 'longitude' => $lng, 'place' => $place,
        ]);
        GpsEvent::create(['type' => GpsEventType::BadgeIn, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver?->id, 'geofence_id' => $zone?->id, 'occurred_at' => $at, 'payload' => ['badge' => $badge]]);

        $who = $driver?->full_name ?? "Badge inconnu $badge";
        $this->activity('badge_in', "$who a badgé dans {$vehicle->name}", $vehicle, $at, [
            'type' => 'in', 'source' => $source, 'driver' => $driver?->full_name, 'driver_id' => $driver?->id, 'badge' => $badge, 'place' => $place, 'anomaly' => $anomaly?->value, 'shift_id' => $shift->id,
        ]);
        if ($anomaly) {
            $this->alert($anomaly, $vehicle, "$who · {$vehicle->name} · $place");
        }

        return $shift;
    }

    public function badgeOut(Vehicle $vehicle, string $badge, CarbonInterface $at, float $lat, float $lng, string $source = 'badge'): ?DriverShift
    {
        $driver = Driver::where('badge_number', $badge)->first();
        $zone = $this->zones->zonesAt($lat, $lng)->sortBy(fn ($z) => $z->type === GeofenceType::Depot ? 0 : 1)->first();
        $place = $zone?->name ?? Places::label($lat, $lng);
        $shift = DriverShift::where('vehicle_id', $vehicle->id)->whereNull('end_at')->latest('start_at')->first();

        if ($shift) {
            $this->close($shift, $at, $lat, $lng, $place);
        }
        BadgeEvent::create([
            'badge_number' => $badge, 'driver_id' => $driver?->id, 'vehicle_id' => $vehicle->id, 'driver_shift_id' => $shift?->id,
            'type' => BadgeType::Out, 'source' => $source, 'occurred_at' => $at, 'latitude' => $lat, 'longitude' => $lng, 'place' => $place,
        ]);
        GpsEvent::create(['type' => GpsEventType::BadgeOut, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver?->id, 'geofence_id' => $zone?->id, 'occurred_at' => $at, 'payload' => ['badge' => $badge]]);
        $who = $driver?->full_name ?? "Badge inconnu $badge";
        $this->activity('badge_out', "$who a badgé la sortie de {$vehicle->name}", $vehicle, $at, [
            'type' => 'out', 'source' => $source, 'driver' => $driver?->full_name, 'driver_id' => $driver?->id, 'badge' => $badge, 'place' => $place, 'shift_id' => $shift?->id,
            'duration_seconds' => $shift?->duration_seconds, 'distance_km' => $shift?->distance_km,
        ]);

        return $shift;
    }

    /**
     * Services ouverts dont le camion est revenu au dépôt, moteur coupé depuis plus de 15 min,
     * sans badge de sortie : signalés « sortie non pointée » (le service reste ouvert).
     */
    public function flagUnbadgedExits(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $count = 0;
        DriverShift::with(['vehicle.liveStatus.geofence', 'driver'])->whereNull('end_at')->whereNull('anomaly')->get()
            ->each(function (DriverShift $s) use ($now, &$count) {
                $live = $s->vehicle?->liveStatus;
                if ($live && $live->geofence?->type === GeofenceType::Depot && $live->status === LiveStatus::Stopped && ! $live->ignition
                    && $live->status_since && $live->status_since->lte($now->copy()->subMinutes(self::UNBADGED_AFTER_MINUTES))
                    && $live->status_since->gt($s->start_at)) {
                    $s->update(['anomaly' => ShiftAnomaly::ExitNotBadged]);
                    $this->alert(ShiftAnomaly::ExitNotBadged, $s->vehicle, ($s->driver?->full_name ?? 'Chauffeur inconnu')." · {$s->vehicle->name} · retour au dépôt sans badge");
                    $count++;
                }
            });

        return $count;
    }

    /** Distance parcourue (km) entre deux instants, à partir des positions. */
    public function distanceKm(int $vehicleId, CarbonInterface $from, CarbonInterface $to): float
    {
        $m = 0.0;
        $prev = null;
        foreach (Position::where('vehicle_id', $vehicleId)->whereBetween('recorded_at', [$from, $to])->orderBy('recorded_at')->cursor() as $p) {
            if ($prev) {
                $m += Geo::distanceM($prev->latitude, $prev->longitude, $p->latitude, $p->longitude);
            }
            $prev = $p;
        }

        return round($m / 1000, 2);
    }

    private function close(DriverShift $shift, CarbonInterface $at, ?float $lat, ?float $lng, ?string $place): void
    {
        $shift->update([
            'end_at' => $at, 'end_lat' => $lat, 'end_lng' => $lng, 'end_place' => $place,
            'duration_seconds' => max(0, $at->getTimestamp() - $shift->start_at->getTimestamp()),
            'distance_km' => $this->distanceKm($shift->vehicle_id, $shift->start_at, $at),
        ]);
    }

    /** Clôture d'un service sans badge de sortie : fin = dernière arrivée au dépôt (sinon maintenant). */
    private function closeUnbadged(DriverShift $shift, CarbonInterface $at): void
    {
        $arrival = VehicleZoneVisit::where('vehicle_id', $shift->vehicle_id)->where('entered_at', '>', $shift->start_at)
            ->whereHas('geofence', fn ($q) => $q->where('type', GeofenceType::Depot))->latest('entered_at')->first();
        $end = $arrival?->entered_at ?? $at;
        $this->close($shift, $end, null, null, $arrival ? $arrival->geofence->name : null);
        if ($shift->anomaly === null) {
            $shift->update(['anomaly' => ShiftAnomaly::ExitNotBadged]);
        }
    }

    private function activity(string $kind, string $message, Vehicle $vehicle, CarbonInterface $at, array $data): void
    {
        if ($this->notify) {
            Realtime::send(new FleetActivity($kind, $message, $vehicle->id, $data, $at->toIso8601String()));
        }
    }

    private function alert(ShiftAnomaly $anomaly, Vehicle $vehicle, string $detail): void
    {
        if (! $this->notify) {
            return;
        }
        Realtime::send(new FleetActivity('anomaly', "{$anomaly->label()} : $detail", $vehicle->id, ['anomaly' => $anomaly->value]));
        $this->alerts->send(new FleetAlert('shift_anomaly', $anomaly->label(), $detail, 'danger', $vehicle->id, '/pointages'));
    }
}
