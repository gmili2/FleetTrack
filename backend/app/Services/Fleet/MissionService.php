<?php

namespace App\Services\Fleet;

use App\Enums\DriverStatus;
use App\Enums\MissionEventType;
use App\Enums\MissionStatus;
use App\Events\FleetActivity;
use App\Models\AuditLog;
use App\Models\Driver;
use App\Models\Mission;
use App\Models\User;
use App\Services\Tracking\ShiftService;
use App\Support\Realtime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cycle de vie d'une mission : création → démarrage → fin / annulation.
 * Règle : un camion ou un chauffeur n'a jamais plus d'une mission en cours.
 */
class MissionService
{
    public function __construct(private ShiftService $shifts) {}

    public function create(array $data, User $by): Mission
    {
        return DB::transaction(function () use ($data, $by) {
            // Verrou sur les références de l'année pour éviter les doublons.
            Mission::withTrashed()->where('reference', 'like', 'MIS-'.now('Africa/Casablanca')->year.'-%')->lockForUpdate()->count();
            $mission = Mission::create($data + [
                'reference' => Mission::nextReference((int) now('Africa/Casablanca')->year),
                'status' => MissionStatus::Pending, 'created_by' => $by->id,
            ]);
            $this->log($mission, MissionEventType::Created, 'Mission créée', $by);
            AuditLog::record('created', $mission, "Mission créée : {$mission->reference}");

            return $mission;
        });
    }

    public function update(Mission $mission, array $data, User $by): Mission
    {
        $mission->update($data);
        $this->log($mission, MissionEventType::Updated, 'Mission modifiée', $by);

        return $mission;
    }

    public function start(Mission $mission, User $by): Mission
    {
        return DB::transaction(function () use ($mission, $by) {
            $mission = Mission::lockForUpdate()->findOrFail($mission->id);
            $this->expect($mission, [MissionStatus::Pending], 'démarrée');
            $busy = Mission::where('status', MissionStatus::InProgress)->whereKeyNot($mission->id)
                ->where(fn ($q) => $q->where('vehicle_id', $mission->vehicle_id)->orWhere('driver_id', $mission->driver_id))
                ->lockForUpdate()->with('vehicle:id,name', 'driver:id,first_name,last_name')->first();
            if ($busy) {
                $who = $busy->vehicle_id === $mission->vehicle_id ? "Le camion {$busy->vehicle->name}" : "Le chauffeur {$busy->driver->full_name}";
                throw ValidationException::withMessages(['mission' => "$who est déjà en mission ({$busy->reference}). Terminez-la avant d’en démarrer une autre."]);
            }
            $mission->update(['status' => MissionStatus::InProgress, 'actual_start_at' => now()]);
            Driver::whereKey($mission->driver_id)->update(['status' => DriverStatus::OnMission]);
            $this->log($mission, MissionEventType::Started, 'Statut : En attente → En cours', $by);
            $this->broadcast($mission, 'démarrée');

            return $mission;
        });
    }

    public function complete(Mission $mission, User $by): Mission
    {
        return DB::transaction(function () use ($mission, $by) {
            $mission = Mission::lockForUpdate()->findOrFail($mission->id);
            $this->expect($mission, [MissionStatus::InProgress], 'terminée');
            $end = now();
            $mission->update([
                'status' => MissionStatus::Completed, 'actual_end_at' => $end,
                'distance_km' => $this->shifts->distanceKm($mission->vehicle_id, $mission->actual_start_at, $end),
            ]);
            $this->releaseDriver($mission);
            $this->log($mission, MissionEventType::Completed, 'Statut : En cours → Terminée', $by);
            $this->broadcast($mission, 'terminée');

            return $mission;
        });
    }

    public function cancel(Mission $mission, string $reason, User $by): Mission
    {
        return DB::transaction(function () use ($mission, $reason, $by) {
            $mission = Mission::lockForUpdate()->findOrFail($mission->id);
            $this->expect($mission, [MissionStatus::Pending, MissionStatus::InProgress], 'annulée');
            $was = $mission->status;
            $mission->update(['status' => MissionStatus::Cancelled, 'cancel_reason' => $reason, 'actual_end_at' => $was === MissionStatus::InProgress ? now() : null]);
            if ($was === MissionStatus::InProgress) {
                $this->releaseDriver($mission);
            }
            $this->log($mission, MissionEventType::Cancelled, "Mission annulée : $reason", $by);
            $this->broadcast($mission, 'annulée');

            return $mission;
        });
    }

    public function comment(Mission $mission, string $text, User $by): void
    {
        $this->log($mission, MissionEventType::Comment, "Commentaire : $text", $by);
    }

    private function expect(Mission $m, array $statuses, string $verb): void
    {
        if (! in_array($m->status, $statuses, true)) {
            throw ValidationException::withMessages(['mission' => "Une mission « {$m->status->label()} » ne peut pas être $verb."]);
        }
    }

    private function releaseDriver(Mission $mission): void
    {
        $other = Mission::where('driver_id', $mission->driver_id)->where('status', MissionStatus::InProgress)->whereKeyNot($mission->id)->exists();
        if (! $other) {
            Driver::whereKey($mission->driver_id)->where('status', DriverStatus::OnMission)->update(['status' => DriverStatus::Available]);
        }
    }

    private function log(Mission $m, MissionEventType $type, string $message, ?User $by): void
    {
        $m->events()->create(['type' => $type, 'message' => $message, 'user_id' => $by?->id, 'occurred_at' => now()]);
    }

    private function broadcast(Mission $m, string $verb): void
    {
        $m->loadMissing('vehicle:id,name', 'driver:id,first_name,last_name');
        Realtime::send(new FleetActivity('mission', "Mission {$m->reference} $verb", $m->vehicle_id, ['mission_id' => $m->id, 'driver' => $m->driver?->full_name]));
    }
}
