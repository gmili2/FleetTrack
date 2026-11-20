<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Activité de la flotte (entrée/sortie de zone, badge, alerte…) pour les flux « en direct ».
 * kind : zone_enter | zone_exit | badge_in | badge_out | overspeed | offline | online | anomaly
 */
class FleetActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $kind,
        public string $message,
        public ?int $vehicleId = null,
        public array $data = [],
        public ?string $at = null,
    ) {
        $this->at ??= now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('fleet')];
    }

    public function broadcastAs(): string
    {
        return 'activity';
    }
}
