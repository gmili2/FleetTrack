<?php

namespace App\Enums;

/** Statut GPS en direct. */
enum LiveStatus: string
{
    use HasOptions;

    case Moving = 'moving';
    case Stopped = 'stopped';
    case Offline = 'offline';

    public function label(): string
    {
        return match ($this) {
            self::Moving => 'En mouvement',
            self::Stopped => 'À l’arrêt',
            self::Offline => 'Hors ligne',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Moving => 'green',
            self::Stopped => 'orange',
            self::Offline => 'gray',
        };
    }
}
