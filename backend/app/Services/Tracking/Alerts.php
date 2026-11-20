<?php

namespace App\Services\Tracking;

use App\Models\User;
use App\Notifications\FleetAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Envoie les alertes de flotte aux utilisateurs qui suivent la flotte (permission tracking.view). */
class Alerts
{
    private ?Collection $recipients = null;
    private int $loadedAt = 0;

    public function send(FleetAlert $alert): void
    {
        if ($this->recipients === null || time() - $this->loadedAt > 300) {
            $this->recipients = User::where('is_active', true)->get()->filter(fn (User $u) => $u->can('tracking.view'))->values();
            $this->loadedAt = time();
        }

        try {
            Notification::send($this->recipients, $alert);
        } catch (Throwable $e) {
            Log::warning('Alerte non diffusée : '.$e->getMessage());
        }
    }
}
