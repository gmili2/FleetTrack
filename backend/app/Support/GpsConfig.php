<?php

namespace App\Support;

/**
 * Configuration d'une carte GPS : paramètres envoyés au boîtier (protocole, serveur, APN, cadence d'envoi…).
 * Les valeurs absentes prennent les valeurs par défaut ci-dessous.
 */
final class GpsConfig
{
    public const PROTOCOLS = [
        'teltonika' => 'Teltonika (Codec 8)',
        'gt06' => 'Concox GT06',
        'tk103' => 'TK103',
        'osmand' => 'OsmAnd (HTTP)',
    ];

    public const OPERATORS = ['maroc_telecom' => 'Maroc Telecom', 'orange' => 'Orange', 'inwi' => 'inwi'];

    public const DEFAULTS = [
        'protocol' => 'teltonika',
        'server_host' => 'gps.fleettrack.ma',
        'server_port' => 5027,
        'apn' => null,
        'interval_moving_s' => 10,
        'interval_stopped_s' => 300,
        'angle_change_deg' => 30,
        'distance_m' => 200,
        'overspeed_kmh' => null,
        'ignition_detection' => true,
        'harsh_driving' => false,
    ];

    /** Règles de validation (préfixe « config. »). */
    public static function rules(): array
    {
        return [
            'config' => ['nullable', 'array'],
            'config.protocol' => ['required_with:config', 'in:'.implode(',', array_keys(self::PROTOCOLS))],
            'config.server_host' => ['required_with:config', 'string', 'max:120', 'regex:/^[A-Za-z0-9.-]+$/'],
            'config.server_port' => ['required_with:config', 'integer', 'between:1,65535'],
            'config.apn' => ['nullable', 'string', 'max:60'],
            'config.interval_moving_s' => ['required_with:config', 'integer', 'between:1,3600'],
            'config.interval_stopped_s' => ['required_with:config', 'integer', 'between:10,86400'],
            'config.angle_change_deg' => ['required_with:config', 'integer', 'between:0,180'],
            'config.distance_m' => ['required_with:config', 'integer', 'between:0,10000'],
            'config.overspeed_kmh' => ['nullable', 'integer', 'between:30,150'],
            'config.ignition_detection' => ['required_with:config', 'boolean'],
            'config.harsh_driving' => ['required_with:config', 'boolean'],
        ];
    }

    public static function attributes(): array
    {
        return [
            'config.protocol' => 'protocole', 'config.server_host' => 'serveur', 'config.server_port' => 'port', 'config.apn' => 'APN',
            'config.interval_moving_s' => 'intervalle en mouvement', 'config.interval_stopped_s' => 'intervalle à l’arrêt',
            'config.angle_change_deg' => 'changement de cap', 'config.distance_m' => 'distance', 'config.overspeed_kmh' => 'seuil de survitesse',
        ];
    }

    /** Configuration complète (valeurs enregistrées + valeurs par défaut), limitée aux clés connues. */
    public static function merge(?array $config): array
    {
        $out = array_merge(self::DEFAULTS, array_intersect_key($config ?? [], self::DEFAULTS));
        foreach (['server_port', 'interval_moving_s', 'interval_stopped_s', 'angle_change_deg', 'distance_m'] as $k) {
            $out[$k] = (int) $out[$k];
        }
        $out['overspeed_kmh'] = $out['overspeed_kmh'] === null ? null : (int) $out['overspeed_kmh'];
        foreach (['ignition_detection', 'harsh_driving'] as $k) {
            $out[$k] = (bool) $out[$k];
        }

        return $out;
    }
}
