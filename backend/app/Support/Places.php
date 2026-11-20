<?php

namespace App\Support;

/** Lieux connus de Casablanca pour afficher une adresse lisible sans géocodage externe. */
final class Places
{
    private const PLACES = [
        ['Route de Rabat, Aïn Sebaâ', 33.6080, -7.5250],
        ['Bd Mohammed VI, Casablanca', 33.5700, -7.6000],
        ['Autoroute A3, sortie Mohammedia', 33.6600, -7.4300],
        ['Autoroute A3, Aïn Harrouda', 33.6400, -7.4500],
        ['Zenata', 33.6500, -7.4800],
        ['Mohammedia centre', 33.6870, -7.3830],
        ['Bd Al Qods, Sidi Maârouf', 33.5250, -7.6300],
        ['Autoroute urbaine, Sidi Maârouf', 33.5450, -7.6200],
        ['Sidi Bernoussi', 33.6150, -7.4950],
        ['Bd des Almohades, Port', 33.6050, -7.6100],
        ['Roches Noires', 33.5980, -7.5800],
        ['Bd Moulay Ismaïl, Casablanca', 33.5950, -7.5900],
        ['Hay Mohammadi', 33.5900, -7.5600],
        ['Bd de la Grande Ceinture, Sidi Moumen', 33.5850, -7.5300],
        ['Bd Zerktouni, Maârif', 33.5850, -7.6300],
        ['Bd Ghandi, Casablanca', 33.5730, -7.6380],
        ['Place Mohammed V, Casablanca', 33.5920, -7.6180],
        ['Derb Sultan', 33.5770, -7.6000],
        ['Aïn Chock', 33.5450, -7.5950],
        ['Bd Oqba Ibn Nafii, Hay Hassani', 33.5520, -7.6600],
        ['Hay Hassani', 33.5600, -7.6700],
        ['Oulfa', 33.5550, -7.6900],
        ['Lissasfa', 33.5330, -7.6800],
        ['Anfa', 33.5880, -7.6600],
        ['Bouskoura', 33.4600, -7.6500],
    ];

    public static function label(float $lat, float $lng): string
    {
        $best = null;
        $bestD = INF;
        foreach (self::PLACES as [$name, $pLat, $pLng]) {
            $d = Geo::distanceM($lat, $lng, $pLat, $pLng);
            if ($d < $bestD) {
                [$best, $bestD] = [$name, $d];
            }
        }

        return $bestD <= 4000 ? $best : 'Casablanca';
    }
}
