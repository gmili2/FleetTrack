<?php

namespace App\Policies;

use App\Enums\MissionStatus;
use App\Models\Mission;
use App\Models\User;

class MissionPolicy
{
    public function viewAny(User $user): bool { return $user->can('missions.view'); }
    public function view(User $user, Mission $mission): bool { return $user->can('missions.view'); }
    public function create(User $user): bool { return $user->can('missions.create'); }
    /** Seule une mission en attente se modifie librement. */
    public function update(User $user, Mission $mission): bool { return $user->can('missions.update') && $mission->status === MissionStatus::Pending; }
    /** Démarrer / terminer / annuler / commenter. */
    public function operate(User $user, Mission $mission): bool { return $user->can('missions.operate'); }
    public function delete(User $user, Mission $mission): bool { return $user->can('missions.delete') && in_array($mission->status, [MissionStatus::Pending, MissionStatus::Cancelled], true); }
    public function export(User $user): bool { return $user->can('missions.export'); }
}
