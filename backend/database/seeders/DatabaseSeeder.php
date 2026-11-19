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
            // Étape 2 : camions, chauffeurs, boîtiers, zones, missions…
        ]);
    }
}
