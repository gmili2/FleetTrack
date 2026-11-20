<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;

/** Un compte de démonstration par rôle, mot de passe « password ». */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['superadmin@demo.ma', 'Amine Berrada', Roles::SUPER_ADMIN, '0661 20 30 40'],
            ['admin@demo.ma', 'Nadia Chraibi', Roles::ADMIN, '0662 11 22 33'],
            ['flotte@demo.ma', 'Salma Bennani', Roles::FLEET_MANAGER, '0663 44 55 66'],
            ['operateur@demo.ma', 'Karim Tazi', Roles::OPERATOR, '0664 77 88 99'],
            ['chauffeur@demo.ma', 'Jamal Ouazzani', Roles::DRIVER, '+212 6 00 00 00 13'],
        ];

        foreach ($users as [$email, $name, $role, $phone]) {
            $user = User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'phone' => $phone,
                'password' => 'password',
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now(), 'failed_login_attempts' => 0, 'locked_until' => null])->save();
            $user->syncRoles([$role]);
            if ($role === Roles::DRIVER) {
                // Compte lié au chauffeur Jamal Ouazzani (CH-1096, badge B-4446).
                $user->forceFill(['driver_id' => \App\Models\Driver::where('employee_number', 'CH-1096')->value('id')])->save();
            }
        }
    }
}
