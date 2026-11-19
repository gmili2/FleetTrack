<?php

namespace App\Enums;

enum GpsEventType: string
{
    use HasOptions;

    case ZoneEnter = 'zone_enter';
    case ZoneExit = 'zone_exit';
    case Overspeed = 'overspeed';
    case Stop = 'stop';
    case Offline = 'offline';
    case Online = 'online';
    case IgnitionOn = 'ignition_on';
    case IgnitionOff = 'ignition_off';
    case BadgeIn = 'badge_in';
    case BadgeOut = 'badge_out';

    public function label(): string
    {
        return match ($this) {
            self::ZoneEnter => 'Entrée en zone',
            self::ZoneExit => 'Sortie de zone',
            self::Overspeed => 'Excès de vitesse',
            self::Stop => 'Arrêt',
            self::Offline => 'Perte du signal',
            self::Online => 'Signal retrouvé',
            self::IgnitionOn => 'Contact mis',
            self::IgnitionOff => 'Contact coupé',
            self::BadgeIn => 'Prise de service',
            self::BadgeOut => 'Fin de service',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::ZoneEnter, self::BadgeIn, self::Online, self::IgnitionOn => 'green',
            self::ZoneExit, self::BadgeOut => 'blue',
            self::Overspeed => 'red',
            self::Stop => 'orange',
            default => 'gray',
        };
    }
}
