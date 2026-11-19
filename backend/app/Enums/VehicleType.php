<?php

namespace App\Enums;

enum VehicleType: string
{
    use HasOptions;

    case Tractor = 'tractor';
    case Rigid = 'rigid';
    case Tipper = 'tipper';
    case Tanker = 'tanker';
    case Van = 'van';

    public function label(): string
    {
        return match ($this) {
            self::Tractor => 'Tracteur',
            self::Rigid => 'Porteur',
            self::Tipper => 'Benne',
            self::Tanker => 'Citerne',
            self::Van => 'Fourgon',
        };
    }
}
