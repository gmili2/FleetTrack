<?php

namespace App\Support;

/** Calculs géographiques simples (WGS84). */
final class Geo
{
    private const EARTH_RADIUS_M = 6371008.8;

    /** Distance en mètres entre deux points (formule de haversine). */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1, sqrt($a)));
    }

    /** Cap en degrés (0 = nord, 90 = est) pour aller de A vers B. */
    public static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $y = sin(deg2rad($lng2 - $lng1)) * cos(deg2rad($lat2));
        $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($lng2 - $lng1));

        return ((int) round(rad2deg(atan2($y, $x))) + 360) % 360;
    }

    /** Point dans un cercle (centre + rayon en mètres). */
    public static function inCircle(float $lat, float $lng, float $cLat, float $cLng, float $radiusM): bool
    {
        return self::distanceM($lat, $lng, $cLat, $cLng) <= $radiusM;
    }

    /**
     * Point dans un polygone (lancer de rayon).
     *
     * @param  list<array{0: float, 1: float}>  $polygon  [[lat, lng], ...]
     */
    public static function inPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $n = count($polygon);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$latI, $lngI] = $polygon[$i];
            [$latJ, $lngJ] = $polygon[$j];
            if ((($lngI > $lng) !== ($lngJ > $lng))
                && ($lat < ($latJ - $latI) * ($lng - $lngI) / (($lngJ - $lngI) ?: 1e-12) + $latI)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Centre (moyenne des sommets) d'un polygone.
     *
     * @param  list<array{0: float, 1: float}>  $polygon
     * @return array{0: float, 1: float}
     */
    public static function centroid(array $polygon): array
    {
        $n = max(1, count($polygon));

        return [array_sum(array_column($polygon, 0)) / $n, array_sum(array_column($polygon, 1)) / $n];
    }
}
