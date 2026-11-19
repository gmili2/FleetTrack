<?php

namespace App\Enums;

enum DriverStatus: string
{
    use HasOptions;

    case Available = 'available';
    case OnMission = 'on_mission';
    case Rest = 'rest';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::OnMission => 'En mission',
            self::Rest => 'Repos',
            self::Inactive => 'Inactif',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Available => 'green',
            self::OnMission => 'blue',
            self::Rest, self::Inactive => 'gray',
        };
    }
}
