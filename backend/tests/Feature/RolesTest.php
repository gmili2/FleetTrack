<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

$u = fn (string $email) => User::where('email', $email)->firstOrFail();

it('donne tous les droits au super administrateur', function () use ($u) {
    expect($u('superadmin@demo.ma')->can('users.delete'))->toBeTrue()
        ->and($u('superadmin@demo.ma')->can('update', $u('admin@demo.ma')))->toBeTrue();
});

it('interdit à l’administrateur de gérer un super administrateur', function () use ($u) {
    $admin = $u('admin@demo.ma');
    expect($admin->can('settings.update'))->toBeTrue()
        ->and($admin->can('update', $u('superadmin@demo.ma')))->toBeFalse()
        ->and($admin->can('delete', $u('superadmin@demo.ma')))->toBeFalse()
        ->and($admin->can('update', $u('operateur@demo.ma')))->toBeTrue()
        ->and($admin->can('assignRole', [User::class, 'super_admin']))->toBeFalse();
});

it('limite le responsable de flotte (ni utilisateurs ni paramètres)', function () use ($u) {
    $m = $u('flotte@demo.ma');
    expect($m->can('vehicles.delete'))->toBeTrue()
        ->and($m->can('reports.export'))->toBeTrue()
        ->and($m->can('users.view'))->toBeFalse()
        ->and($m->can('settings.update'))->toBeFalse();
});

it('limite l’opérateur à la lecture et aux missions, sans suppression ni export', function () use ($u) {
    $o = $u('operateur@demo.ma');
    expect($o->can('vehicles.view'))->toBeTrue()
        ->and($o->can('missions.create'))->toBeTrue()
        ->and($o->can('missions.operate'))->toBeTrue()
        ->and($o->can('vehicles.update'))->toBeFalse()
        ->and($o->can('missions.delete'))->toBeFalse()
        ->and($o->can('reports.export'))->toBeFalse()
        ->and($o->can('users.view'))->toBeFalse();
});
