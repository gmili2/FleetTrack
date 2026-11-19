<?php

namespace App\Enums;

enum GeofenceType: string
{
    use HasOptions;

    case Depot = 'depot';
    case Factory = 'factory';
    case Customer = 'customer';
    case Site = 'site';
    case DeliveryZone = 'delivery_zone';

    public function label(): string
    {
        return match ($this) {
            self::Depot => 'Dépôt',
            self::Factory => 'Usine',
            self::Customer => 'Client',
            self::Site => 'Chantier',
            self::DeliveryZone => 'Zone de livraison',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Depot => 'blue',
            self::Factory => 'gray',
            self::Customer => 'green',
            self::Site => 'orange',
            self::DeliveryZone => 'blue',
        };
    }

    /** Couleur par défaut sur la carte. */
    public function color(): string
    {
        return match ($this) {
            self::Depot => '#1D4ED8',
            self::Factory => '#7C3AED',
            self::Customer => '#15803D',
            self::Site => '#C2410C',
            self::DeliveryZone => '#0E7490',
        };
    }
}
