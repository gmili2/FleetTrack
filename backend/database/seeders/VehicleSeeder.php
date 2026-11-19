<?php

namespace Database\Seeders;

use App\Enums\LiveStatus;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Driver;
use App\Models\Geofence;
use App\Models\GpsDevice;
use App\Models\Position;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleLiveStatus;
use Illuminate\Database\Seeder;

/** 12 camions, 12 boîtiers GPS, affectations et position de départ au dépôt. */
class VehicleSeeder extends Seeder
{
    public const VEHICLES = [
        // nom, immatriculation, type, marque, modèle, année, vitesse max, chauffeur (matricule)
        ['CAM-07', '48213-A-1', VehicleType::Tractor, 'Volvo', 'FH 460', 2021, 90, 'CH-1012'],
        ['CAM-03', '51877-B-6', VehicleType::Tractor, 'Renault', 'T 480', 2020, 90, 'CH-1019'],
        ['CAM-15', '39042-A-6', VehicleType::Rigid, 'Mercedes', 'Actros', 2022, 90, 'CH-1026'],
        ['CAM-12', '62310-D-1', VehicleType::Tipper, 'MAN', 'TGS 33.400', 2019, 80, 'CH-1033'],
        ['CAM-09', '27765-A-1', VehicleType::Tanker, 'Scania', 'R 450', 2021, 80, 'CH-1040'],
        ['CAM-18', '44128-B-6', VehicleType::Rigid, 'Isuzu', 'FVR', 2023, 90, 'CH-1047'],
        ['CAM-21', '58903-A-6', VehicleType::Van, 'Iveco', 'Daily', 2022, 100, 'CH-1054'],
        ['CAM-24', '30551-A-1', VehicleType::Rigid, 'DAF', 'CF', 2018, 90, null],
        ['CAM-01', '17420-A-1', VehicleType::Tractor, 'Volvo', 'FH 500', 2023, 90, 'CH-1068'],
        ['CAM-05', '22814-B-6', VehicleType::Tipper, 'Renault', 'C 430', 2020, 80, 'CH-1075'],
        ['CAM-11', '35609-A-1', VehicleType::Tanker, 'Mercedes', 'Arocs', 2022, 80, 'CH-1082'],
        ['CAM-14', '49372-D-1', VehicleType::Van, 'Renault', 'Master', 2024, 100, 'CH-1089'],
    ];

    public function run(): void
    {
        $depot = Geofence::where('name', GeofenceSeeder::DEPOT)->firstOrFail();
        $now = now();

        foreach (self::VEHICLES as $i => [$name, $plate, $type, $brand, $model, $year, $limit, $matricule]) {
            $driver = $matricule ? Driver::where('employee_number', $matricule)->first() : null;
            $inMaintenance = $name === 'CAM-24';

            $vehicle = Vehicle::updateOrCreate(['name' => $name], [
                'plate_number' => $plate,
                'type' => $type,
                'brand' => $brand,
                'model' => $model,
                'year' => $year,
                'status' => $inMaintenance ? VehicleStatus::Maintenance : VehicleStatus::Active,
                'speed_limit_kmh' => $limit,
                'odometer_km' => 60000 + (23417 * ($i + 1)) % 400000,
                'current_driver_id' => $driver?->id,
            ]);

            GpsDevice::updateOrCreate(['imei' => sprintf('86%013d', 4471 + 1117 * $i)], [
                'model' => $i % 3 === 0 ? 'Teltonika FMC130' : 'Teltonika FMB920',
                'sim_number' => sprintf('+212 7 00 10 %02d %02d', $i + 10, 30 + $i),
                'vehicle_id' => $vehicle->id,
                'is_active' => true,
                'installed_at' => $now->copy()->subMonths(10 + $i),
                'last_seen_at' => $now,
            ]);

            if ($driver && ! $vehicle->assignments()->whereNull('ended_at')->exists()) {
                VehicleAssignment::create(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'started_at' => $now->copy()->subMonths(3)->startOfDay()]);
            }

            // Position de départ : garés au dépôt (léger décalage pour ne pas se superposer).
            $lat = $depot->center_lat + (($i % 4) - 1.5) * 0.00035;
            $lng = $depot->center_lng + (intdiv($i, 4) - 1) * 0.0005;
            $at = $inMaintenance ? $now->copy()->subDay()->setTime(17, 5) : $now->copy()->subMinutes(2);
            if (! $vehicle->positions()->exists()) {
                Position::create(['vehicle_id' => $vehicle->id, 'latitude' => $lat, 'longitude' => $lng, 'speed_kmh' => 0, 'course' => 0, 'ignition' => false, 'recorded_at' => $at]);
            }
            VehicleLiveStatus::updateOrCreate(['vehicle_id' => $vehicle->id], [
                'latitude' => $lat, 'longitude' => $lng, 'speed_kmh' => 0, 'course' => 0, 'ignition' => false,
                'status' => $inMaintenance ? LiveStatus::Offline : LiveStatus::Stopped,
                'status_since' => $at,
                'address' => $inMaintenance ? 'Atelier' : $depot->name,
                'geofence_id' => $depot->id,
                'today_distance_km' => 0,
                'last_position_at' => $at,
            ]);
        }
    }
}
