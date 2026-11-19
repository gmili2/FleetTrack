<?php

namespace App\Enums;

enum BadgeType: string
{
    use HasOptions;

    case In = 'in';
    case Out = 'out';

    public function label(): string
    {
        return $this === self::In ? 'Entrée' : 'Sortie';
    }
}
