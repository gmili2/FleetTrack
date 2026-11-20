<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** Nouvelle position / nouveau statut d'un camion, diffusé sur le canal privé « fleet ». */
class PositionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param array<string, mixed> $vehicle  Charge utile identique à GET /api/live */
    public function __construct(public array $vehicle) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('fleet')];
    }

    public function broadcastAs(): string
    {
        return 'position.updated';
    }
}
