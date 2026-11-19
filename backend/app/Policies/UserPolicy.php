<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;

/**
 * Gestion des utilisateurs : un Administrateur gère tout le monde
 * sauf les Super Administrateurs (le Super Administrateur passe par Gate::before).
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.update') && ! $model->isSuperAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('users.delete') && ! $model->isSuperAdmin() && $user->id !== $model->id;
    }

    /** Seul un Super Administrateur peut attribuer le rôle Super Administrateur. */
    public function assignRole(User $user, string $role): bool
    {
        return $user->can('users.update') && $role !== Roles::SUPER_ADMIN;
    }
}
