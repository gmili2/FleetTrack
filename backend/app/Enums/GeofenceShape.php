<?php

namespace App\Enums;

enum GeofenceShape: string
{
    use HasOptions;

    case Circle = 'circle';
    case Polygon = 'polygon';

    public function label(): string
    {
        return $this === self::Circle ? 'Cercle' : 'Polygone';
    }
}
