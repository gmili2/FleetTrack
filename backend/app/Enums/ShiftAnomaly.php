<?php

namespace App\Enums;

enum ShiftAnomaly: string
{
    use HasOptions;

    case OutsideDepot = 'outside_depot';
    case ExitNotBadged = 'exit_not_badged';
    case UnknownBadge = 'unknown_badge';

    public function label(): string
    {
        return match ($this) {
            self::OutsideDepot => 'Prise hors dépôt',
            self::ExitNotBadged => 'Sortie non pointée',
            self::UnknownBadge => 'Badge inconnu',
        };
    }

    public function tone(): string
    {
        return 'red';
    }
}
