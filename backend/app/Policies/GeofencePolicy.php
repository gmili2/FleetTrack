<?php

namespace App\Policies;

use App\Models\Geofence;
use App\Models\User;

class GeofencePolicy
{
    public function viewAny(User $user): bool { return $user->can('geofences.view'); }
    public function view(User $user, Geofence $geofence): bool { return $user->can('geofences.view'); }
    public function create(User $user): bool { return $user->can('geofences.create'); }
    public function update(User $user, Geofence $geofence): bool { return $user->can('geofences.update'); }
    public function delete(User $user, Geofence $geofence): bool { return $user->can('geofences.delete'); }
    public function export(User $user): bool { return $user->can('geofences.export'); }
}
