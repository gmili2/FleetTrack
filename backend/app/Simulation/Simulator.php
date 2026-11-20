<?php

namespace App\Simulation;

use App\Enums\LiveStatus;
use App\Enums\MissionStatus;
use App\Enums\VehicleStatus;
use App\Models\DriverShift;
use App\Models\Geofence;
use App\Models\Mission;
use App\Models\Vehicle;
use App\Services\Tracking\LivePayload;
use App\Services\Tracking\PositionIngestor;
use App\Services\Tracking\ShiftService;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;

/**
 * Fait rouler les camions virtuels sur les itinéraires de RouteBook.
 *
 * Cycle d'un camion : badge d'entrée au dépôt → 1 à 3 trajets (dépôt → client/site → éventuellement
 * un 2e site → dépôt) avec arrêts de 5 à 60 min → badge de sortie → pause → nouveau service.
 * Une mission en cours impose son départ et sa destination.
 *
 * $speedFactor accélère le temps simulé (distance et attentes) sans changer les vitesses affichées.
 */
class Simulator
{
    /** @var array<int, TruckSim> */
    public array $trucks = [];
    private float $simTime = 0;
    private ?Closure $log = null;

    public function __construct(
        private PositionIngestor $ingestor,
        private ShiftService $shifts,
        public int $interval = 5,
        public float $speedFactor = 1.0,
    ) {}

    public function onLog(Closure $log): void
    {
        $this->log = $log;
    }

    /** Charge les camions actifs et reconstitue leur état à partir de la dernière position connue. */
    public function boot(): void
    {
        $firstRun = ! DriverShift::exists();
        $zones = Geofence::all()->keyBy(fn ($z) => RouteBook::keyOf($z->name));
        $vehicles = Vehicle::with(LivePayload::WITH)->where('status', VehicleStatus::Active)->orderBy('name')->get();

        foreach ($vehicles as $i => $v) {
            $t = new TruckSim($v);
            $t->lat = $v->liveStatus?->latitude ?? $zones['depot']->center_lat;
            $t->lng = $v->liveStatus?->longitude ?? $zones['depot']->center_lng;
            $t->course = $v->liveStatus?->course ?? 0;
            $t->zone = $this->zoneKeyAt($t->lat, $t->lng);
            $t->onShift = DriverShift::where('vehicle_id', $v->id)->whereNull('end_at')->exists();
            $t->tripsLeft = $t->onShift ? random_int(1, 2) : 0;

            if ($t->zone === null) {
                $this->resumeOnNearestLeg($t);
            } else {
                // Départs échelonnés ; les camions en mission partent tout de suite.
                $t->until = $this->hasMission($v) ? 0 : random_int(0, 180) + $i * 10;
            }
            $this->trucks[$v->id] = $t;
        }

        if ($firstRun) {
            $this->scriptFirstRunAnomalies($zones);
        }
        $this->say(count($this->trucks).' camions chargés'.($firstRun ? ' (premier lancement : scénario d’anomalies activé)' : ''));
    }

    /** Un pas de simulation (appelé toutes les $interval secondes). */
    public function tick(?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $dt = $this->interval * $this->speedFactor;
        $this->simTime += $dt;
        $this->maybeDropSignal($now);

        foreach ($this->trucks as $t) {
            match ($t->state) {
                TruckSim::PARKED => $this->parked($t, $now),
                TruckSim::DWELL => $this->dwell($t),
                TruckSim::DRIVING => $this->drive($t, $dt),
            };
            if (! $t->isOffline($now->getTimestamp())) {
                $this->ingestor->ingest($t->vehicle, round($t->lat, 6), round($t->lng, 6), $t->speed, $t->course, $t->ignition, $now);
            }
        }
    }

    // ── États ──────────────────────────────────────────────────────────────────

    private function parked(TruckSim $t, CarbonImmutable $now): void
    {
        $t->speed = 0;
        $t->ignition = false;
        if ($this->simTime < $t->until) {
            return;
        }
        if (! $t->onShift) {
            $this->startShift($t, $now);
        }
        $this->planTour($t);
        $this->departNextLeg($t);
    }

    private function dwell(TruckSim $t): void
    {
        $t->speed = 0;
        $t->ignition = false;
        if ($this->simTime >= $t->until) {
            $this->departNextLeg($t);
        }
    }

    private function drive(TruckSim $t, float $dt): void
    {
        $t->ignition = true;
        $remaining = $this->remainingMeters($t);

        // Arrêts brefs : feux, ronds-points, bouchons.
        if ($t->holdTicks > 0) {
            $t->holdTicks--;
            $t->speed = max(0, $t->speed - 25);

            return;
        }
        if ($remaining > 400 && $t->speed > 20 && random_int(1, 45) === 1) {
            $t->holdTicks = random_int(2, 8);
        }

        $target = $remaining < 250 ? 18 : ($remaining < 600 ? 35 : $t->cruise + random_int(-6, 6));
        $old = $t->speed;
        $t->speed = max(0, min(95, $old + max(-18, min(10, $target - $old))));
        $meters = (($old + $t->speed) / 2) / 3.6 * $dt;

        if ($this->advance($t, max($meters, 3))) {
            $this->arrive($t);
        }
    }

    // ── Tournées ───────────────────────────────────────────────────────────────

    private function planTour(TruckSim $t): void
    {
        $mission = Mission::with(['origin', 'destination'])->where('vehicle_id', $t->vehicle->id)->where('status', MissionStatus::InProgress)->first();
        if ($mission && ! in_array($mission->id, $t->doneMissions, true)
            && ($o = RouteBook::keyOf($mission->origin?->name ?? '')) && ($d = RouteBook::keyOf($mission->destination?->name ?? ''))) {
            $t->missionId = $mission->id;
            $t->plan = array_values(array_filter([$o !== $t->zone ? $o : null, $d, $d !== 'depot' ? 'depot' : null]));

            return;
        }

        $first = collect(RouteBook::neighbours('depot'))->random();
        $t->plan = [$first];
        $second = collect(RouteBook::neighbours($first))->reject(fn ($k) => $k === 'depot')->values();
        if ($second->isNotEmpty() && random_int(1, 3) === 1) {
            $t->plan[] = $second->random();
        }
        $t->plan[] = 'depot';
    }

    private function departNextLeg(TruckSim $t): void
    {
        $next = array_shift($t->plan);
        if ($next === null) {
            $this->endOfTour($t);

            return;
        }
        $path = $t->zone ? RouteBook::path($t->zone, $next) : null;
        if ($path === null && $t->zone && $t->zone !== 'depot') {
            // Pas de trajet direct : on passe par le dépôt.
            array_unshift($t->plan, $next);
            $next = 'depot';
            $path = RouteBook::path($t->zone, 'depot');
        }
        if ($path === null) {
            $t->state = TruckSim::PARKED;
            $t->until = $this->simTime + 60;

            return;
        }

        // Départ depuis la position réelle du camion (place de parking, quai…).
        $long = $next === 'usine' || $t->zone === 'usine';
        array_unshift($path, [$t->lat, $t->lng]);
        $t->path = $path;
        $t->segment = 0;
        $t->segmentDone = 0;
        $t->destination = $next;
        $t->zone = null;
        $t->state = TruckSim::DRIVING;
        $t->ignition = true;
        $limit = $t->vehicle->speed_limit_kmh;
        // ~15 % des trajets en léger excès de vitesse.
        $t->cruise = random_int(1, 7) === 1 ? min(95, $limit + random_int(4, 8)) : random_int($long ? 65 : 45, $long ? min(88, $limit) : min(72, $limit));
        $this->say("{$t->vehicle->name} part vers ".RouteBook::ZONES[$next]);
    }

    private function arrive(TruckSim $t): void
    {
        $t->zone = $t->destination;
        $t->destination = null;
        $t->speed = 0;
        $t->holdTicks = 0;
        // Se garer légèrement à l'intérieur de la zone (le dernier point est le centre).
        $t->lat += random_int(-60, 60) / 1e6;
        $t->lng += random_int(-60, 60) / 1e6;

        if ($t->zone === 'depot' && empty($t->plan)) {
            $this->endOfTour($t);

            return;
        }
        $t->state = TruckSim::DWELL;
        // Arrêt chez le client / sur site : 5 à 60 min (souvent 10 à 25).
        $t->until = $this->simTime + 60 * (random_int(1, 5) === 1 ? random_int(30, 60) : random_int(5, 25));
        $this->say("{$t->vehicle->name} arrivé à ".RouteBook::ZONES[$t->zone]);
    }

    private function endOfTour(TruckSim $t): void
    {
        $t->state = TruckSim::PARKED;
        $t->speed = 0;
        $t->ignition = false;
        $t->tripsLeft--;
        if ($t->missionId) {
            $t->doneMissions[] = $t->missionId;
            $t->missionId = null;
        }
        if ($t->tripsLeft > 0) {
            $t->until = $this->simTime + 60 * random_int(3, 8);

            return;
        }
        // Fin de service : badge de sortie (sauf oubli), puis pause.
        if ($t->onShift && ! $t->forgetBadgeOut && ($badge = $t->vehicle->currentDriver?->badge_number)) {
            $this->shifts->badgeOut($t->vehicle, $badge, CarbonImmutable::now(), $t->lat, $t->lng);
            $this->say("{$t->vehicle->name} : fin de service badgée");
        } elseif ($t->onShift) {
            $this->say("{$t->vehicle->name} : retour au dépôt SANS badge de sortie");
        }
        $t->onShift = false;
        $t->forgetBadgeOut = false;
        $t->until = $this->simTime + 60 * random_int(5, 20);
    }

    private function startShift(TruckSim $t, CarbonImmutable $now, ?string $badge = null): void
    {
        $badge ??= random_int(1, 40) === 1 ? 'B-9'.random_int(100, 999) : $t->vehicle->currentDriver?->badge_number; // rare : badge inconnu
        if (! $badge) {
            return;
        }
        $this->shifts->badgeIn($t->vehicle, $badge, $now, $t->lat, $t->lng);
        $t->onShift = true;
        $t->tripsLeft = random_int(1, 3);
        $t->forgetBadgeOut = random_int(1, 12) === 1;
        $this->say("{$t->vehicle->name} : prise de service ($badge)");
    }

    // ── Scénario & pannes ──────────────────────────────────────────────────────

    /** Premier lancement : 1 prise de service hors dépôt (CAM-15 à l'usine) et 1 sortie non pointée (CAM-21). */
    private function scriptFirstRunAnomalies(Collection $zones): void
    {
        foreach ($this->trucks as $t) {
            if ($t->vehicle->name === 'CAM-15' && isset($zones['usine'])) {
                $t->lat = $zones['usine']->center_lat;
                $t->lng = $zones['usine']->center_lng;
                $t->zone = 'usine';
                $this->startShift($t, CarbonImmutable::now(), $t->vehicle->currentDriver?->badge_number);
                $t->forgetBadgeOut = false;
                $t->plan = ['port', 'depot'];
                $t->until = 0;
                $t->state = TruckSim::DWELL;
            }
            if ($t->vehicle->name === 'CAM-21') {
                $t->tripsLeft = 1;
                $t->until = 30;
                $this->startShift($t, CarbonImmutable::now(), $t->vehicle->currentDriver?->badge_number);
                $t->tripsLeft = 1;
                $t->forgetBadgeOut = true;
            }
        }
    }

    /** De temps en temps (≈ toutes les 45 min), un camion perd le signal 12 à 18 min. */
    private function maybeDropSignal(CarbonImmutable $now): void
    {
        $ts = $now->getTimestamp();
        foreach ($this->trucks as $t) {
            if ($t->isOffline($ts)) {
                return;
            }
        }
        if (random_int(1, (int) (2700 / $this->interval)) === 1) {
            $candidates = array_filter($this->trucks, fn (TruckSim $t) => $t->state === TruckSim::DRIVING);
            if ($candidates) {
                $t = $candidates[array_rand($candidates)];
                $t->offlineUntil = $ts + 60 * random_int(12, 18);
                $this->say("{$t->vehicle->name} : perte de signal simulée");
            }
        }
    }

    // ── Géométrie ──────────────────────────────────────────────────────────────

    /** Avance de $meters le long du tracé ; renvoie true à l'arrivée. */
    private function advance(TruckSim $t, float $meters): bool
    {
        while ($t->segment < count($t->path) - 1) {
            [$aLat, $aLng] = $t->path[$t->segment];
            [$bLat, $bLng] = $t->path[$t->segment + 1];
            $len = Geo::distanceM($aLat, $aLng, $bLat, $bLng);
            $left = $len - $t->segmentDone;
            if ($meters < $left) {
                $t->segmentDone += $meters;
                $f = $len > 0 ? $t->segmentDone / $len : 1;
                $t->lat = $aLat + ($bLat - $aLat) * $f;
                $t->lng = $aLng + ($bLng - $aLng) * $f;
                if ($len > 1) {
                    $t->course = Geo::bearing($aLat, $aLng, $bLat, $bLng);
                }

                return false;
            }
            $meters -= $left;
            $t->segment++;
            $t->segmentDone = 0;
            [$t->lat, $t->lng] = [$bLat, $bLng];
        }

        return true;
    }

    private function remainingMeters(TruckSim $t): float
    {
        $m = 0;
        for ($i = $t->segment; $i < count($t->path) - 1; $i++) {
            $m += Geo::distanceM($t->path[$i][0], $t->path[$i][1], $t->path[$i + 1][0], $t->path[$i + 1][1]);
            if ($m > 1000) {
                break; // suffisant pour décider du freinage
            }
        }

        return max(0, $m - $t->segmentDone);
    }

    private function zoneKeyAt(float $lat, float $lng): ?string
    {
        foreach (Geofence::all() as $z) {
            if ($z->contains($lat, $lng) && ($k = RouteBook::keyOf($z->name))) {
                return $k;
            }
        }

        return null;
    }

    /** Camion relancé en pleine route : reprend le tracé le plus proche jusqu'à son terminus. */
    private function resumeOnNearestLeg(TruckSim $t): void
    {
        $best = [INF, null, 0];
        foreach (RouteBook::LEGS as $key => $pts) {
            foreach ([$pts, array_reverse($pts)] as $dir => $path) {
                foreach ($path as $i => [$lat, $lng]) {
                    $d = Geo::distanceM($t->lat, $t->lng, $lat, $lng);
                    if ($d < $best[0]) {
                        $best = [$d, [$key, $dir], $i];
                    }
                }
            }
        }
        [, [$key, $dir], $i] = $best;
        [$a, $b] = explode('>', $key);
        $path = $dir ? array_reverse(RouteBook::LEGS[$key]) : RouteBook::LEGS[$key];
        $t->path = array_merge([[$t->lat, $t->lng]], array_slice($path, $i));
        $t->segment = 0;
        $t->segmentDone = 0;
        $t->destination = $dir ? $a : $b;
        $t->plan = $t->destination === 'depot' ? [] : ['depot'];
        $t->state = TruckSim::DRIVING;
        $t->cruise = random_int(45, 70);
        $t->zone = null;
        $t->tripsLeft = max(1, $t->tripsLeft);
    }

    private function hasMission(Vehicle $v): bool
    {
        return Mission::where('vehicle_id', $v->id)->where('status', MissionStatus::InProgress)->exists();
    }

    private function say(string $msg): void
    {
        if ($this->log) {
            ($this->log)($msg);
        }
    }
}
