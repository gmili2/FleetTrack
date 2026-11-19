<?php

namespace App\Enums;

enum MissionEventType: string
{
    use HasOptions;

    case Created = 'created';
    case Updated = 'updated';
    case Started = 'started';
    case ZoneExit = 'zone_exit';
    case ZoneEnter = 'zone_enter';
    case Comment = 'comment';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Mission créée',
            self::Updated => 'Mission modifiée',
            self::Started => 'Mission démarrée',
            self::ZoneExit => 'Sortie de zone',
            self::ZoneEnter => 'Entrée en zone',
            self::Comment => 'Commentaire',
            self::Completed => 'Mission terminée',
            self::Cancelled => 'Mission annulée',
        };
    }
}
