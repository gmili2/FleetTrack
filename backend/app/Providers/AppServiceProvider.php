<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Le Super Administrateur a tous les droits.
        Gate::before(fn (User $user) => $user->hasRole(Roles::SUPER_ADMIN) ? true : null);
    }
}
