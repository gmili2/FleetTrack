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
        // Services de suivi partagés (l'anti-rebond des zones garde un état en mémoire).
        $this->app->singleton(\App\Services\Tracking\Alerts::class);
        $this->app->singleton(\App\Services\Tracking\GeofenceDetector::class);
        $this->app->singleton(\App\Services\Tracking\PositionIngestor::class);
        $this->app->singleton(\App\Services\Tracking\ShiftService::class);
    }

    public function boot(): void
    {
        // Le Super Administrateur a tous les droits.
        Gate::before(fn (User $user) => $user->hasRole(Roles::SUPER_ADMIN) ? true : null);
    }
}
