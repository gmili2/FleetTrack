<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/** Alerte flotte (zone, excès de vitesse, perte de signal, anomalie de pointage). */
class FleetAlert extends Notification
{
    use Queueable;

    /** level : info | success | warning | danger */
    public function __construct(
        public string $kind,
        public string $title,
        public string $message,
        public string $level = 'info',
        public ?int $vehicleId = null,
        public ?string $link = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'level' => $this->level,
            'vehicle_id' => $this->vehicleId,
            'link' => $this->link,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable) + ['created_at' => now()->toIso8601String()]))->onConnection('sync');
    }

    public function broadcastType(): string
    {
        return 'fleet.alert';
    }
}
