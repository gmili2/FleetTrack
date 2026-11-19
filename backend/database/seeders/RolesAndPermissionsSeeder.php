<?php

namespace Database\Seeders;

use App\Support\Roles;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Roles::allPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach (array_keys(Roles::LABELS) as $role) {
            Role::findOrCreate($role, 'web')->syncPermissions(Roles::permissionsFor($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
