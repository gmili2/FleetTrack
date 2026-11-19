<?php

namespace App\Enums;

/** Statut administratif du camion. */
enum VehicleStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Maintenance => 'Maintenance',
            self::Inactive => 'Inactif',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Maintenance => 'orange',
            self::Inactive => 'gray',
        };
    }
}
