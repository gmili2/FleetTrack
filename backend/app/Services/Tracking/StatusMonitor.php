<?php

namespace App\Services\Tracking;

use App\Enums\GpsEventType;
use App\Enums\LiveStatus;
use App\Events\FleetActivity;
use App\Events\PositionUpdated;
use App\Models\GpsEvent;
use App\Models\Setting;
use App\Models\Vehicle;
use App\Models\VehicleLiveStatus;
use App\Notifications\FleetAlert;
use App\Support\Realtime;

/** Passe « hors ligne » les camions sans position depuis le seuil configuré (10 min par défaut). */
class StatusMonitor
{
    public function __construct(private Alerts $alerts) {}

    public function markOffline(): int
    {
        $minutes = (int) Setting::get('thresholds.offline_minutes', 10);
        $stale = VehicleLiveStatus::where('status', '!=', LiveStatus::Offline)
            ->where('last_position_at', '<', now()->subMinutes($minutes))->pluck('vehicle_id');

        foreach (Vehicle::with(LivePayload::WITH)->whereIn('id', $stale)->get() as $vehicle) {
            $live = $vehicle->liveStatus;
            $live->update(['status' => LiveStatus::Offline, 'status_since' => $live->last_position_at, 'speed_kmh' => 0]);
            GpsEvent::create(['type' => GpsEventType::Offline, 'vehicle_id' => $vehicle->id, 'driver_id' => $vehicle->current_driver_id, 'occurred_at' => $live->last_position_at]);
            $msg = "{$vehicle->name} n’émet plus depuis plus de $minutes min.";
            Realtime::send(new PositionUpdated(LivePayload::make($vehicle)));
            Realtime::send(new FleetActivity('offline', $msg, $vehicle->id));
            $this->alerts->send(new FleetAlert('offline', 'Camion hors ligne', $msg, 'warning', $vehicle->id, "/suivi?v={$vehicle->id}"));
        }

        return $stale->count();
    }
}
