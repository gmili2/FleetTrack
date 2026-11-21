<?php

namespace App\Support;

/**
 * Rôles et permissions de FleetTrack.
 *
 * Les permissions sont nommées « module.action ». Le Super Administrateur passe
 * tous les contrôles (Gate::before) ; l'Administrateur a tout, mais la UserPolicy
 * lui interdit de gérer les comptes Super Administrateur.
 */
final class Roles
{
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN = 'admin';
    public const FLEET_MANAGER = 'fleet_manager';
    public const OPERATOR = 'operator';
    public const DRIVER = 'driver';

    public const LABELS = [
        self::SUPER_ADMIN => 'Super Administrateur',
        self::ADMIN => 'Administrateur',
        self::FLEET_MANAGER => 'Responsable de flotte',
        self::OPERATOR => 'Opérateur',
        self::DRIVER => 'Chauffeur',
    ];

    /** Actions disponibles par module. */
    public const MODULES = [
        'dashboard' => ['view'],
        'tracking' => ['view'],
        'history' => ['view'],
        'vehicles' => ['view', 'create', 'update', 'delete', 'export'],
        'drivers' => ['view', 'create', 'update', 'delete', 'export'],
        // Cartes GPS (boîtiers) et leur configuration.
        'devices' => ['view', 'create', 'update', 'delete'],
        'shifts' => ['view', 'export'],
        'missions' => ['view', 'create', 'update', 'operate', 'delete', 'export'],
        'geofences' => ['view', 'create', 'update', 'delete', 'export'],
        'reports' => ['view', 'export'],
        'users' => ['view', 'create', 'update', 'delete'],
        'settings' => ['view', 'update'],
        'simulator' => ['manage'],
        // Espace chauffeur : pointer l'entrée / la sortie, suivre son service.
        'driver_portal' => ['use'],
    ];

    /** @return list<string> */
    public static function allPermissions(): array
    {
        $all = [];
        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action) {
                $all[] = "$module.$action";
            }
        }

        return $all;
    }

    /** @return list<string> */
    public static function permissionsFor(string $role): array
    {
        // L'espace chauffeur est réservé au rôle Chauffeur.
        $all = array_values(array_filter(self::allPermissions(), fn (string $p) => ! str_starts_with($p, 'driver_portal.')));

        return match ($role) {
            self::SUPER_ADMIN, self::ADMIN => $all,
            // Gestion de la flotte, sans utilisateurs ni paramètres.
            self::FLEET_MANAGER => array_values(array_filter(
                $all,
                fn (string $p) => ! str_starts_with($p, 'users.') && ! str_starts_with($p, 'settings.') && $p !== 'simulator.manage',
            )),
            // Lecture partout + gestion des missions ; ni suppression ni export.
            self::OPERATOR => array_values(array_filter(
                $all,
                fn (string $p) => (str_ends_with($p, '.view') && ! str_starts_with($p, 'users.') && ! str_starts_with($p, 'settings.'))
                    || in_array($p, ['missions.create', 'missions.update', 'missions.operate'], true),
            )),
            self::DRIVER => ['driver_portal.use'],
            default => [],
        };
    }

    public static function label(?string $role): string
    {
        return self::LABELS[$role] ?? (string) $role;
    }
}
