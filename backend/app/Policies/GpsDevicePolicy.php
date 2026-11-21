<?php

namespace App\Policies;

use App\Models\GpsDevice;
use App\Models\User;

class GpsDevicePolicy
{
    public function viewAny(User $user): bool { return $user->can('devices.view'); }
    public function view(User $user, GpsDevice $device): bool { return $user->can('devices.view'); }
    public function create(User $user): bool { return $user->can('devices.create'); }
    public function update(User $user, GpsDevice $device): bool { return $user->can('devices.update'); }
    public function delete(User $user, GpsDevice $device): bool { return $user->can('devices.delete'); }
}
