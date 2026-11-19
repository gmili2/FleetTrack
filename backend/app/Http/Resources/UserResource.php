<?php

namespace App\Http\Resources;

use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->getRoleNames()->first();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'initials' => $this->initials(),
            'is_active' => $this->is_active,
            'role' => $role,
            'role_label' => Roles::label($role),
            'permissions' => $this->when(
                $request->routeIs('auth.me', 'auth.login'),
                fn () => $this->isSuperAdmin() ? Roles::allPermissions() : $this->getAllPermissions()->pluck('name')->values(),
            ),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
