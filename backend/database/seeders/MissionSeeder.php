<?php

namespace Database\Seeders;

use App\Enums\DriverStatus;
use App\Enums\MissionEventType;
use App\Enums\MissionStatus;
use App\Models\Geofence;
use App\Models\Mission;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Missions dans tous les statuts, avec leur journal d'événements.
 * Les heures sont pensées en heure de Casablanca puis stockées en UTC.
 */
class MissionSeeder extends Seeder
{
    private const TZ = 'Africa/Casablanca';

    private const CARGO = [
        'Livraison 24 t de ciment', 'Granulats 18 t', 'Palettes alimentaires (22)', 'Fer à béton 12 t',
        'Conteneur 40 pieds', 'Carburant 30 000 L', 'Matériaux de construction', 'Colis messagerie',
    ];

    public function run(): void
    {
        if (Mission::exists()) {
            return;
        }

        $z = Geofence::pluck('id', 'name');
        $zones = Geofence::all()->keyBy('id');
        $v = Vehicle::with('currentDriver')->get()->keyBy('name');
        $author = User::where('email', 'flotte@demo.ma')->value('id');
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $now = CarbonImmutable::now()->startOfMinute();
        // Jour J : heures relatives à maintenant (« +45 » = dans 45 min) pour une démo cohérente à toute heure.
        $at = fn (int $dayOffset, string $hm) => $dayOffset === 0 && preg_match('/^[+-]\d+$/', $hm)
            ? $now->addMinutes((int) $hm)
            : $today->addDays($dayOffset)->setTimeFromTimeString($hm)->utc();

        $depot = GeofenceSeeder::DEPOT;
        // camion, départ, destination, jour, début, fin prévue, statut
        $plan = [
            // En cours (maquette)
            ['CAM-12', $depot, 'Client — Atlas Béton', 0, '-20', '+60', MissionStatus::InProgress],
            ['CAM-07', $depot, 'Client — Atlas Béton', 0, '-10', '+80', MissionStatus::InProgress],
            ['CAM-03', 'Usine Mohammedia', 'Zone de livraison Port', 0, '-5', '+115', MissionStatus::InProgress],
            // En attente
            ['CAM-18', $depot, 'Chantier Lissasfa', 0, '+180', '+330', MissionStatus::Pending],
            ['CAM-09', $depot, 'Client — Maghreb Agro', 0, '+240', '+420', MissionStatus::Pending],
            ['CAM-01', $depot, 'Client — Sahara Distribution', 1, '08:00', '10:00', MissionStatus::Pending],
            ['CAM-05', 'Usine Mohammedia', 'Client — Casa Matériaux', 1, '09:30', '12:30', MissionStatus::Pending],
            // Terminée aujourd'hui
            ['CAM-09', 'Usine Mohammedia', $depot, 0, '-300', '-190', MissionStatus::Completed],
            // Annulées
            ['CAM-21', $depot, 'Chantier Lissasfa', -1, '15:00', '17:00', MissionStatus::Cancelled],
            ['CAM-11', $depot, 'Zone de livraison Port', -4, '08:30', '10:30', MissionStatus::Cancelled],
            ['CAM-14', $depot, 'Client — Maghreb Agro', -7, '14:00', '15:30', MissionStatus::Cancelled],
        ];

        // Historique : missions terminées sur les 10 derniers jours.
        $routes = [
            [$depot, 'Client — Atlas Béton'], ['Usine Mohammedia', 'Zone de livraison Port'], [$depot, 'Chantier Lissasfa'],
            [$depot, 'Client — Maghreb Agro'], ['Usine Mohammedia', $depot], [$depot, 'Client — Sahara Distribution'],
            [$depot, 'Client — Casa Matériaux'], ['Zone de livraison Port', $depot],
        ];
        $active = ['CAM-07', 'CAM-03', 'CAM-15', 'CAM-12', 'CAM-09', 'CAM-18', 'CAM-21', 'CAM-01', 'CAM-05', 'CAM-11', 'CAM-14'];
        for ($k = 0; $k < 22; $k++) {
            $day = -1 - intdiv($k, 2) % 10;
            $start = $k % 2 ? '13:30' : '08:15';
            $end = $k % 2 ? '16:00' : '11:00';
            [$from, $to] = $routes[$k % count($routes)];
            $plan[] = [$active[($k * 5) % count($active)], $from, $to, $day, $start, $end, MissionStatus::Completed];
        }

        usort($plan, fn ($a, $b) => $at($a[3], $a[4]) <=> $at($b[3], $b[4]));

        DB::transaction(function () use ($plan, $z, $zones, $v, $author, $at) {
            foreach ($plan as $n => [$cam, $from, $to, $day, $start, $end, $status]) {
                $vehicle = $v[$cam];
                $origin = $zones[$z[$from]];
                $dest = $zones[$z[$to]];
                $plannedStart = $at($day, $start);
                $plannedEnd = $at($day, $end);
                $km = round(Geo::distanceM($origin->center_lat, $origin->center_lng, $dest->center_lat, $dest->center_lng) / 1000 * 1.3, 1);
                $late = ($n % 6 === 0) ? 25 : -5; // quelques missions terminées en retard
                $actualStart = in_array($status, [MissionStatus::InProgress, MissionStatus::Completed], true) ? $plannedStart->addMinutes(($n % 4) * 3) : null;
                $actualEnd = $status === MissionStatus::Completed ? $plannedEnd->addMinutes($late) : null;

                $mission = Mission::create([
                    'reference' => sprintf('MIS-%s-%05d', $plannedStart->setTimezone(self::TZ)->format('Y'), 100 + $n),
                    'vehicle_id' => $vehicle->id,
                    'driver_id' => $vehicle->current_driver_id,
                    'customer_id' => $dest->customer_id ?? $origin->customer_id,
                    'origin_geofence_id' => $origin->id,
                    'destination_geofence_id' => $dest->id,
                    'planned_start_at' => $plannedStart,
                    'planned_end_at' => $plannedEnd,
                    'actual_start_at' => $actualStart,
                    'actual_end_at' => $actualEnd,
                    'status' => $status,
                    'cargo' => self::CARGO[$n % count(self::CARGO)],
                    'distance_km' => $status === MissionStatus::Completed ? $km : ($status === MissionStatus::InProgress ? round($km * 0.6, 1) : null),
                    'cancel_reason' => $status === MissionStatus::Cancelled ? 'Client indisponible, livraison reportée' : null,
                    'created_by' => $author,
                ]);

                $log = fn (MissionEventType $t, string $msg, $when, ?int $user = null) => $mission->events()->create(['type' => $t, 'message' => $msg, 'user_id' => $user, 'occurred_at' => $when]);
                $log(MissionEventType::Created, 'Mission créée', $plannedStart->subHours(2)->subMinutes(15), $author);
                if ($actualStart) {
                    $log(MissionEventType::Started, 'Statut : En attente → En cours', $actualStart->subMinute(), $author);
                    $log(MissionEventType::ZoneExit, "Sortie de la zone « {$origin->name} »", $actualStart);
                }
                if ($n % 3 === 0 && $actualStart) {
                    $log(MissionEventType::Comment, 'Commentaire : '.mb_strtolower($mission->cargo), $actualStart->addMinutes(14), $author);
                }
                if ($actualEnd) {
                    $log(MissionEventType::ZoneEnter, "Entrée dans la zone « {$dest->name} »", $actualEnd->subMinutes(20));
                    $log(MissionEventType::Completed, 'Statut : En cours → Terminée', $actualEnd, $author);
                }
                if ($status === MissionStatus::Cancelled) {
                    $log(MissionEventType::Cancelled, 'Mission annulée : '.$mission->cancel_reason, $plannedStart->subMinutes(30), $author);
                }
                if ($status === MissionStatus::InProgress) {
                    $vehicle->currentDriver?->update(['status' => DriverStatus::OnMission]);
                }
            }
        });
    }
}
