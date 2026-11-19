<?php

namespace App\Enums;

enum MissionStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::InProgress => 'En cours',
            self::Completed => 'Terminée',
            self::Cancelled => 'Annulée',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::InProgress => 'blue',
            self::Completed => 'green',
            self::Cancelled => 'red',
        };
    }
}
