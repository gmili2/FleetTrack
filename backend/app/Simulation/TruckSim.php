<?php

namespace App\Simulation;

use App\Models\Vehicle;

/** État en mémoire d'un camion virtuel. */
class TruckSim
{
    public const PARKED = 'parked';     // garé, moteur coupé, en attente du prochain départ
    public const DRIVING = 'driving';   // sur un itinéraire
    public const DWELL = 'dwell';       // arrêt chez un client / sur un site

    public string $state = self::PARKED;
    public float $lat;
    public float $lng;
    public float $speed = 0;
    public int $course = 0;
    public bool $ignition = false;

    /** Zone (clé RouteBook) où se trouve le camion, null en route. */
    public ?string $zone = 'depot';
    /** @var list<array{0: float, 1: float}> */
    public array $path = [];
    public int $segment = 0;          // index du point de départ du segment courant
    public float $segmentDone = 0;    // mètres parcourus sur le segment courant
    /** Étapes restantes de la tournée (clés RouteBook). @var list<string> */
    public array $plan = [];
    public ?string $destination = null;

    public float $cruise = 60;        // vitesse de croisière visée sur ce trajet
    public int $holdTicks = 0;        // arrêt bref (feu, bouchon)
    public float $until = 0;          // fin d'attente (temps simulé, secondes)

    public ?int $missionId = null;     // mission suivie sur la tournée en cours
    /** @var list<int> missions déjà effectuées (on ne les rejoue pas) */
    public array $doneMissions = [];

    public bool $onShift = false;
    /** Service ouvert depuis l'application chauffeur : le simulateur ne pointe pas à sa place. */
    public bool $appDriven = false;
    public bool $driverBusyElsewhere = false;
    public int $tripsLeft = 0;        // trajets restants avant la fin du service
    public bool $forgetBadgeOut = false;
    public float $offlineUntil = 0;   // perte de signal jusqu'à (temps réel)

    public function __construct(public Vehicle $vehicle) {}

    public function isOffline(float $now): bool
    {
        return $this->offlineUntil > $now;
    }
}
