<?php

namespace App\Models;

use App\Support\Roles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'is_active', 'driver_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /** Nombre d'échecs avant verrouillage et durée du verrouillage. */
    public const MAX_LOGIN_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** Chauffeur lié au compte (rôle Chauffeur). */
    public function driver(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Roles::SUPER_ADMIN);
    }

    /** Enregistre un échec ; verrouille le compte au 5e échec consécutif. */
    public function registerFailedLogin(): void
    {
        $this->failed_login_attempts++;
        if ($this->failed_login_attempts >= self::MAX_LOGIN_ATTEMPTS) {
            $this->locked_until = now()->addMinutes(self::LOCK_MINUTES);
            $this->failed_login_attempts = 0;
        }
        $this->save();
    }

    public function registerSuccessfulLogin(?string $ip): void
    {
        $this->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return mb_strtoupper(collect($parts)->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode(''));
    }
}
