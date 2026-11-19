<?php

namespace Database\Seeders;

use App\Enums\DriverStatus;
use App\Models\Driver;
use Illuminate\Database\Seeder;

/** 14 chauffeurs : matricules CH-1012, CH-1019… et badges B-4410, B-4413… */
class DriverSeeder extends Seeder
{
    public const DRIVERS = [
        // prénom, nom, n° permis, catégorie, expiration, statut
        ['Youssef', 'Amrani', 'B-284113', 'CE', '2028-12-31', DriverStatus::Available],
        ['Hamza', 'El Idrissi', 'B-190276', 'CE', '2027-03-31', DriverStatus::Available],
        ['Rachid', 'Tazi', 'C-551820', 'C', '2026-12-10', DriverStatus::Available],   // permis à renouveler
        ['Karim', 'Benali', 'B-773019', 'C', '2029-07-31', DriverStatus::Available],
        ['Said', 'Ouali', 'A-118457', 'CE', '2027-09-30', DriverStatus::Available],
        ['Omar', 'Fassi', 'B-640092', 'C', '2030-01-31', DriverStatus::Available],
        ['Nabil', 'Chraibi', 'C-302664', 'B', '2028-05-31', DriverStatus::Available],
        ['Mehdi', 'Alaoui', 'B-905531', 'CE', '2027-02-28', DriverStatus::Rest],
        ['Adil', 'Bennis', 'B-412287', 'CE', '2029-04-30', DriverStatus::Available],
        ['Mustapha', 'Zerouali', 'C-228741', 'C', '2028-10-31', DriverStatus::Available],
        ['Hicham', 'Lahlou', 'A-560318', 'CE', '2027-06-30', DriverStatus::Available],
        ['Anas', 'Kettani', 'B-731905', 'B', '2031-02-28', DriverStatus::Available],
        ['Jamal', 'Ouazzani', 'C-640217', 'C', '2029-11-30', DriverStatus::Available],
        ['Driss', 'Naciri', 'B-118930', 'C', '2026-10-31', DriverStatus::Inactive],   // permis expiré
    ];

    public function run(): void
    {
        foreach (self::DRIVERS as $i => [$first, $last, $license, $cat, $expires, $status]) {
            Driver::updateOrCreate(['employee_number' => 'CH-'.(1012 + 7 * $i)], [
                'badge_number' => 'B-'.(4410 + 3 * $i),
                'first_name' => $first,
                'last_name' => $last,
                'phone' => sprintf('+212 6 00 00 00 %02d', $i + 1),
                'license_number' => $license,
                'license_category' => $cat,
                'license_expires_at' => $expires,
                'status' => $status,
                'hired_at' => now()->subMonths(8 + 7 * $i)->startOfMonth(),
            ]);
        }
    }
}
