<?php

namespace App\Simulation;

use App\Enums\GeofenceType;
use App\Enums\MissionStatus;
use App\Enums\ShiftAnomaly;
use App\Enums\VehicleStatus;
use App\Models\Driver;
use App\Models\Geofence;
use App\Models\Mission;
use App\Models\Vehicle;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Génère un historique cohérent (positions, visites de zones, pointages, événements)
 * sur les N jours précédant aujourd'hui, avec les mêmes itinéraires que le simulateur.
 * Les missions terminées existantes sont rejouées à leur heure prévue (heures réelles mises à jour).
 */
class HistoryGenerator
{
    private const TZ = 'Africa/Casablanca';
    private const MOVE_STEP = 30;    // une position toutes les 30 s en roulant
    private const STOP_STEP = 180;   // toutes les 3 min à l'arrêt
    /** Marque des missions générées (supprimées puis recréées à chaque lancement). */
    public const MARK = 'Historique simulé (fleet:seed-history)';

    private array $positions = [];
    private array $visits = [];
    private array $events = [];
    private Collection $zones;
    private array $zoneIds = [];
    /** Dernière arrivée au dépôt par camion (timestamp) pour la visite « de nuit ». */
    private array $depotSince = [];
    private ?Closure $log = null;
    public int $positionCount = 0;

    public function onLog(Closure $log): void
    {
        $this->log = $log;
    }

    public function run(int $days): array
    {
        $this->zones = Geofence::all()->keyBy(fn ($z) => RouteBook::keyOf($z->name));
        $this->zoneIds = $this->zones->map->id->all();
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $from = $today->subDays($days);

        $this->purge($from->utc(), $today->utc());
        $vehicles = Vehicle::with('currentDriver')->where('status', VehicleStatus::Active)->orderBy('name')->get();
        $substitute = Driver::where('employee_number', 'CH-1061')->first(); // Mehdi Alaoui, remplaçant
        $this->author = \App\Models\User::where('email', 'flotte@demo.ma')->value('id');
        $missions = Mission::with('origin', 'destination')->where('status', MissionStatus::Completed)->whereBetween('planned_start_at', [$from->utc(), $today->utc()])->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', '!=', self::MARK))
            ->get()->groupBy(fn ($m) => $m->vehicle_id.'|'.$m->planned_start_at->setTimezone(self::TZ)->toDateString());
        $shifts = 0;

        for ($day = $from; $day->lt($today); $day = $day->addDay()) {
            foreach ($vehicles as $i => $v) {
                $dayMissions = $missions->get($v->id.'|'.$day->toDateString(), collect())->sortBy('planned_start_at')->values();
                // Dimanche : repos (sauf mission planifiée) ; ~8 % de jours sans sortie.
                if ($dayMissions->isEmpty() && ($day->isSunday() || mt_rand(1, 12) === 1)) {
                    continue;
                }
                $driver = (mt_rand(1, 10) === 1 && $substitute) ? $substitute : $v->currentDriver;
                $this->day($v, $driver, $day, $dayMissions);
                $shifts++;
            }
            $this->flush();
            $this->say($day->format('d/m').' : '.number_format($this->positionCount, 0, ',', ' ').' positions');
        }
        // Visites encore ouvertes (camions rentrés au dépôt le dernier soir) : elles se poursuivent aujourd'hui.
        foreach ($this->openVisit as $vehicleId => [$key, $since, $drv]) {
            if ($zoneId = $this->zoneIds[$key] ?? null) {
                $this->visits[] = ['vehicle_id' => $vehicleId, 'geofence_id' => $zoneId, 'driver_id' => $drv, 'entered_at' => $this->dt($since),
                    'exited_at' => null, 'duration_seconds' => null, 'created_at' => $this->dt($since), 'updated_at' => $this->dt($since)];
            }
        }
        $this->openVisit = [];
        $this->flush(true);
        $this->renumberMissions();

        return ['days' => $days, 'shifts' => $shifts, 'positions' => $this->positionCount, 'missions' => $this->missionCount];
    }

    /** Une journée de travail d'un camion. */
    private function day(Vehicle $v, ?Driver $driver, CarbonImmutable $day, Collection $missions): void
    {
        $depot = $this->zones['depot'];
        $t = $day->setTime(6, 0)->addMinutes(mt_rand(0, 90))->utc()->getTimestamp();
        $shiftStart = $t;
        $endAfter = $day->setTime(15, 30)->addMinutes(mt_rand(0, 120))->utc()->getTimestamp();
        $lat = $depot->center_lat + mt_rand(-25, 25) / 1e5;
        $lng = $depot->center_lng + mt_rand(-25, 25) / 1e5;
        $this->depotSince[$v->id] ??= $t - 10 * 3600;

        // Prise de service par badge (rarement : badge inconnu).
        $unknown = mt_rand(1, 60) === 1;
        $badge = $unknown ? 'B-9'.mt_rand(100, 999) : ($driver?->badge_number ?? 'B-0000');
        $shiftId = DB::table('driver_shifts')->insertGetId([
            'driver_id' => $unknown ? null : $driver?->id, 'vehicle_id' => $v->id, 'badge_number' => $badge, 'source' => 'badge',
            'start_at' => $this->dt($t), 'start_lat' => $lat, 'start_lng' => $lng, 'start_place' => $depot->name,
            'anomaly' => $unknown ? ShiftAnomaly::UnknownBadge->value : null, 'created_at' => $this->dt($t), 'updated_at' => $this->dt($t),
        ]);
        $this->badge($badge, $unknown ? null : $driver?->id, $v->id, $shiftId, 'in', $t, $lat, $lng, $depot->name);
        $this->point($v->id, $lat, $lng, 0, 0, false, $t);
        $meters = 0.0;
        $zone = 'depot';

        $missionQueue = $missions->all();
        while (true) {
            // Tournée : la prochaine mission du jour, sinon 1 ou 2 destinations au hasard.
            $mission = $missionQueue[0] ?? null;
            if ($mission) {
                array_shift($missionQueue);
                $start = $mission->planned_start_at->getTimestamp();
                if ($start > $t) {
                    $this->idle($v->id, $lat, $lng, $t, $start);
                    $t = $start;
                }
                $o = RouteBook::keyOf($mission->origin?->name ?? '') ?? 'depot';
                $d = RouteBook::keyOf($mission->destination?->name ?? '') ?? 'atlas';
                $plan = array_values(array_filter([$o !== $zone ? $o : null, $d, $d !== 'depot' ? 'depot' : null]));
            } else {
                if ($t >= $endAfter) {
                    break;
                }
                $first = collect(RouteBook::neighbours('depot'))->random();
                $plan = [$first];
                $next = collect(RouteBook::neighbours($first))->reject(fn ($k) => $k === 'depot')->values();
                if ($next->isNotEmpty() && mt_rand(1, 3) === 1) {
                    $plan[] = $next->random();
                }
                $plan[] = 'depot';
            }

            $legs = [];
            foreach ($plan as $dest) {
                $path = RouteBook::path($zone, $dest) ?? RouteBook::path($zone, 'depot');
                $dest = RouteBook::path($zone, $dest) ? $dest : 'depot';
                if (! $path) {
                    continue;
                }
                $departure = $t;
                $this->leaveZone($v->id, $zone, $driver?->id, $t);
                [$t, $lat, $lng, $m] = $this->drive($v, $path, $lat, $lng, $t);
                $meters += $m;
                $zone = $dest;
                $legs[] = [$dest, $departure, $t, $m];
                $this->enterZone($v->id, $zone, $driver?->id, $t);
                if ($mission && $dest === RouteBook::keyOf($mission->origin?->name ?? '')) {
                    $mission->actual_start_at = CarbonImmutable::createFromTimestamp($t);
                }
                if ($mission && $dest === RouteBook::keyOf($mission->destination?->name ?? '')) {
                    $mission->actual_start_at ??= CarbonImmutable::createFromTimestamp($departure);
                    $mission->actual_end_at = CarbonImmutable::createFromTimestamp($t);
                    $mission->distance_km = round($m / 1000, 1);
                    $mission->saveQuietly();
                }
                if ($dest !== 'depot') {
                    // Arrêt chez le client / sur site : 10 à 45 min (parfois 60).
                    $dwell = 60 * (mt_rand(1, 6) === 1 ? mt_rand(45, 60) : mt_rand(10, 40));
                    $this->idle($v->id, $lat, $lng, $t, $t + $dwell);
                    $t += $dwell;
                }
            }
            // ~20 % des tournées libres correspondent à une mission (livraison client).
            if (! $mission && $legs && $legs[0][0] !== 'depot' && mt_rand(1, 10) <= 2) {
                $this->recordMission($v, $driver, $legs[0]);
            }
            // Pause au dépôt entre deux tournées.
            $pause = 60 * mt_rand(10, 30);
            $this->idle($v->id, $lat, $lng, $t, $t + $pause);
            $t += $pause;
        }

        // Fin de service : badge de sortie (oubli ~1 fois sur 30).
        $forgot = ! $unknown && mt_rand(1, 30) === 1;
        DB::table('driver_shifts')->where('id', $shiftId)->update([
            'end_at' => $this->dt($t), 'end_lat' => $forgot ? null : $lat, 'end_lng' => $forgot ? null : $lng, 'end_place' => $depot->name,
            'duration_seconds' => $t - $shiftStart,
            'distance_km' => round($meters / 1000, 2),
            'anomaly' => $unknown ? ShiftAnomaly::UnknownBadge->value : ($forgot ? ShiftAnomaly::ExitNotBadged->value : null),
        ]);
        if (! $forgot) {
            $this->badge($badge, $unknown ? null : $driver?->id, $v->id, $shiftId, 'out', $t, $lat, $lng, $depot->name);
        }
        $this->depotSince[$v->id] = $t;
    }

    private ?int $author = null;
    public int $missionCount = 0;

    /** Mission terminée correspondant à un trajet généré (départ du dépôt → destination). */
    private function recordMission(Vehicle $v, ?Driver $driver, array $leg): void
    {
        [$dest, $departure, $arrival, $meters] = $leg;
        if (! $driver) {
            return;
        }
        $destZone = $this->zones[$dest];
        $plannedStart = CarbonImmutable::createFromTimestamp($departure - 60 * mt_rand(0, 15));
        $plannedEnd = $plannedStart->addSeconds((int) (($arrival - $departure) * (mt_rand(105, 140) / 100)) + 1800);
        $m = Mission::create([
            'reference' => 'TMP-'.uniqid(), 'vehicle_id' => $v->id, 'driver_id' => $driver->id, 'customer_id' => $destZone->customer_id,
            'origin_geofence_id' => $this->zoneIds['depot'], 'destination_geofence_id' => $destZone->id,
            'planned_start_at' => $plannedStart, 'planned_end_at' => $plannedEnd,
            'actual_start_at' => CarbonImmutable::createFromTimestamp($departure), 'actual_end_at' => CarbonImmutable::createFromTimestamp($arrival),
            'status' => MissionStatus::Completed, 'cargo' => ['Livraison 24 t de ciment', 'Granulats 18 t', 'Palettes alimentaires (22)', 'Fer à béton 12 t', 'Matériaux de construction'][mt_rand(0, 4)],
            'distance_km' => round($meters / 1000, 1), 'created_by' => $this->author, 'notes' => self::MARK,
        ]);
        $ev = fn (string $type, string $msg, int $ts, bool $auto = false) => [
            'mission_id' => $m->id, 'type' => $type, 'message' => $msg, 'user_id' => $auto ? null : $this->author,
            'occurred_at' => $this->dt($ts), 'created_at' => $this->dt($ts), 'updated_at' => $this->dt($ts),
        ];
        DB::table('mission_events')->insert([
            $ev('created', 'Mission créée', $plannedStart->getTimestamp() - 3600 * mt_rand(1, 18)),
            $ev('started', 'Statut : En attente → En cours', $departure - 60),
            $ev('zone_exit', 'Sortie de la zone « '.$this->zones['depot']->name.' »', $departure, true),
            $ev('zone_enter', "Entrée dans la zone « {$destZone->name} »", $arrival, true),
            $ev('completed', 'Statut : En cours → Terminée', $arrival + 60 * mt_rand(5, 25)),
        ]);
        $this->missionCount++;
    }

    /** Références MIS-AAAA-00001… dans l'ordre chronologique des départs prévus. */
    private function renumberMissions(): void
    {
        DB::transaction(function () {
            DB::table('missions')->update(['reference' => DB::raw("CONCAT('TMP-', id)")]);
            $counters = [];
            foreach (DB::table('missions')->orderBy('planned_start_at')->orderBy('id')->get(['id', 'planned_start_at']) as $m) {
                $year = CarbonImmutable::parse($m->planned_start_at, 'UTC')->setTimezone(self::TZ)->year;
                $counters[$year] = ($counters[$year] ?? 0) + 1;
                DB::table('missions')->where('id', $m->id)->update(['reference' => sprintf('MIS-%d-%05d', $year, $counters[$year])]);
            }
        });
    }

    /** Roule le long d'un tracé ; renvoie [t, lat, lng, mètres]. */
    private function drive(Vehicle $v, array $path, float $lat, float $lng, int $t): array
    {
        array_unshift($path, [$lat, $lng]);
        $limit = $v->speed_limit_kmh;
        // ~1 trajet sur 20 en excès de vitesse ; sinon croisière sous la limite.
        $cruise = mt_rand(1, 20) === 1 ? min(97, $limit + mt_rand(4, 9)) : mt_rand(45, min(80, $limit - 8));
        $speed = 0.0;
        $total = 0.0;
        $overspeed = false;
        $seg = 0;
        $done = 0.0;
        $course = 0;
        $n = count($path);
        $hold = 0;

        while ($seg < $n - 1) {
            $t += self::MOVE_STEP;
            if ($hold > 0) {
                $hold--;
                $speed = 0;
            } else {
                if ($speed > 25 && mt_rand(1, 40) === 1) {
                    $hold = mt_rand(1, 3); // feu rouge, bouchon
                }
                $remaining = $this->remaining($path, $seg, $done);
                $target = $remaining < 300 ? 20 : $cruise + mt_rand(-6, 6);
                $old = $speed;
                $speed = max(5, min(97, $old + max(-25, min(20, $target - $old))));
                $adv = (($old + $speed) / 2) / 3.6 * self::MOVE_STEP;
                $total += $adv;
                while ($seg < $n - 1) {
                    $len = Geo::distanceM($path[$seg][0], $path[$seg][1], $path[$seg + 1][0], $path[$seg + 1][1]);
                    if ($done + $adv < $len) {
                        $done += $adv;
                        break;
                    }
                    $adv -= $len - $done;
                    $seg++;
                    $done = 0;
                }
                if ($seg >= $n - 1) {
                    [$lat, $lng] = $path[$n - 1];
                } else {
                    $len = Geo::distanceM($path[$seg][0], $path[$seg][1], $path[$seg + 1][0], $path[$seg + 1][1]) ?: 1;
                    $f = $done / $len;
                    $lat = $path[$seg][0] + ($path[$seg + 1][0] - $path[$seg][0]) * $f;
                    $lng = $path[$seg][1] + ($path[$seg + 1][1] - $path[$seg][1]) * $f;
                    $course = Geo::bearing($path[$seg][0], $path[$seg][1], $path[$seg + 1][0], $path[$seg + 1][1]);
                }
                if ($speed > $limit && ! $overspeed) {
                    $overspeed = true;
                    $this->event('overspeed', $v->id, null, $v->current_driver_id, $t, ['speed_kmh' => round($speed), 'limit_kmh' => $limit, 'lat' => round($lat, 6), 'lng' => round($lng, 6)]);
                }
            }
            $this->point($v->id, $lat, $lng, $seg >= $n - 1 ? 0 : $speed, $course, true, $t);
        }
        $lat += mt_rand(-40, 40) / 1e6;
        $lng += mt_rand(-40, 40) / 1e6;

        return [$t, $lat, $lng, $total];
    }

    private function remaining(array $path, int $seg, float $done): float
    {
        $m = -$done;
        for ($i = $seg; $i < count($path) - 1 && $m < 400; $i++) {
            $m += Geo::distanceM($path[$i][0], $path[$i][1], $path[$i + 1][0], $path[$i + 1][1]);
        }

        return $m;
    }

    /** Camion à l'arrêt (moteur coupé) entre $from et $to. */
    private function idle(int $vehicleId, float $lat, float $lng, int $from, int $to): void
    {
        for ($t = $from + self::STOP_STEP; $t < $to; $t += self::STOP_STEP) {
            $this->point($vehicleId, $lat, $lng, 0, 0, false, $t);
        }
        $this->point($vehicleId, $lat, $lng, 0, 0, true, $to);
    }

    private array $openVisit = [];

    private function enterZone(int $vehicleId, string $key, ?int $driverId, int $t): void
    {
        if ($key === 'depot') {
            $this->depotSince[$vehicleId] = $t;
        }
        $this->openVisit[$vehicleId] = [$key, $t, $driverId];
        $this->event('zone_enter', $vehicleId, $this->zoneIds[$key] ?? null, $driverId, $t);
    }

    private function leaveZone(int $vehicleId, string $key, ?int $driverId, int $t): void
    {
        [$k, $since, $drv] = $this->openVisit[$vehicleId] ?? [$key, $this->depotSince[$vehicleId] ?? $t - 3600, $driverId];
        unset($this->openVisit[$vehicleId]);
        $zoneId = $this->zoneIds[$k] ?? null;
        if (! $zoneId) {
            return;
        }
        $this->visits[] = [
            'vehicle_id' => $vehicleId, 'geofence_id' => $zoneId, 'driver_id' => $drv, 'entered_at' => $this->dt($since),
            'exited_at' => $this->dt($t), 'duration_seconds' => $t - $since, 'created_at' => $this->dt($since), 'updated_at' => $this->dt($t),
        ];
        $this->event('zone_exit', $vehicleId, $zoneId, $driverId, $t, ['duration_seconds' => $t - $since]);
    }

    private function badge(string $badge, ?int $driverId, int $vehicleId, int $shiftId, string $type, int $t, float $lat, float $lng, string $place): void
    {
        DB::table('badge_events')->insert([
            'badge_number' => $badge, 'driver_id' => $driverId, 'vehicle_id' => $vehicleId, 'driver_shift_id' => $shiftId, 'type' => $type, 'source' => 'badge',
            'occurred_at' => $this->dt($t), 'latitude' => $lat, 'longitude' => $lng, 'place' => $place, 'created_at' => $this->dt($t), 'updated_at' => $this->dt($t),
        ]);
        $this->event($type === 'in' ? 'badge_in' : 'badge_out', $vehicleId, $this->zoneIds['depot'] ?? null, $driverId, $t, ['badge' => $badge]);
    }

    private function point(int $vehicleId, float $lat, float $lng, float $speed, int $course, bool $ignition, int $t): void
    {
        $this->positions[] = [
            'vehicle_id' => $vehicleId, 'latitude' => round($lat, 7), 'longitude' => round($lng, 7), 'speed_kmh' => round($speed, 1),
            'course' => $course, 'ignition' => $ignition, 'recorded_at' => $this->dt($t),
        ];
        if (count($this->positions) >= 2000) {
            $this->flush();
        }
    }

    private function event(string $type, int $vehicleId, ?int $zoneId, ?int $driverId, int $t, ?array $payload = null): void
    {
        $this->events[] = [
            'type' => $type, 'vehicle_id' => $vehicleId, 'geofence_id' => $zoneId, 'driver_id' => $driverId, 'occurred_at' => $this->dt($t),
            'payload' => $payload ? json_encode($payload) : null, 'created_at' => $this->dt($t), 'updated_at' => $this->dt($t),
        ];
    }

    private function flush(bool $all = false): void
    {
        foreach (array_chunk($this->positions, 2000) as $chunk) {
            DB::table('positions')->insert($chunk);
            $this->positionCount += count($chunk);
        }
        foreach (array_chunk($this->visits, 1000) as $chunk) {
            DB::table('vehicle_zone_visits')->insert($chunk);
        }
        foreach (array_chunk($this->events, 1000) as $chunk) {
            DB::table('gps_events')->insert($chunk);
        }
        $this->positions = $this->visits = $this->events = [];
    }

    /** Supprime l'historique déjà généré sur la période (relançable sans doublons). */
    private function purge(CarbonImmutable $from, CarbonImmutable $to): void
    {
        Mission::withTrashed()->where('notes', self::MARK)->forceDelete();
        DB::table('positions')->whereBetween('recorded_at', [$from, $to])->delete();
        DB::table('vehicle_zone_visits')->whereBetween('entered_at', [$from->subDay(), $to])->where(fn ($q) => $q->where('exited_at', '<', $to)->orWhereNull('exited_at'))->delete();
        DB::table('gps_events')->whereBetween('occurred_at', [$from, $to])->delete();
        DB::table('badge_events')->whereBetween('occurred_at', [$from, $to])->delete();
        DB::table('driver_shifts')->whereBetween('start_at', [$from, $to])->whereNotNull('end_at')->delete();
        DB::table('daily_vehicle_stats')->whereBetween('date', [$from->setTimezone(self::TZ)->toDateString(), $to->setTimezone(self::TZ)->subDay()->toDateString()])->delete();
    }

    private function dt(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function say(string $m): void
    {
        if ($this->log) {
            ($this->log)($m);
        }
    }
}
