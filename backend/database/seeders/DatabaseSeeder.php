<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SettingSeeder::class,
            CustomerSeeder::class,
            GeofenceSeeder::class,
            DriverSeeder::class,
            DemoUsersSeeder::class,
            VehicleSeeder::class,
            MissionSeeder::class,
        ]);
    }
}
