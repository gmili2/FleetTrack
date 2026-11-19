<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DemoUsersSeeder::class,
            SettingSeeder::class,
            CustomerSeeder::class,
            GeofenceSeeder::class,
            DriverSeeder::class,
            VehicleSeeder::class,
            MissionSeeder::class,
        ]);
    }
}
