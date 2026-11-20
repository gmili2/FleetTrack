<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Utilisateurs et rôles (un Administrateur ne gère pas les Super Administrateurs : UserPolicy). */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);
        $users = User::with('roles', 'driver')->orderBy('name')->get();

        return response()->json([
            'data' => $users->map(fn (User $u) => (new UserResource($u))->toArray($request) + [
                'locked' => $u->isLocked(),
                'can' => ['update' => $request->user()->can('update', $u), 'delete' => $request->user()->can('delete', $u)],
            ]),
            'meta' => ['roles' => collect(Roles::LABELS)->map(fn ($label, $value) => [
                'value' => $value, 'label' => $label, 'assignable' => $request->user()->can('assignRole', [User::class, $value]),
            ])->values()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', User::class);
        $data = $this->validated($request, null);
        $user = User::create(collect($data)->except('role')->all());
        $user->syncRoles([$data['role']]);
        AuditLog::record('created', $user, "Utilisateur créé : {$user->email} ({$this->label($data['role'])})");

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserResource
    {
        Gate::authorize('update', $user);
        $data = $this->validated($request, $user);
        if ($user->is($request->user()) && (! ($data['is_active'] ?? true) || $data['role'] !== $user->getRoleNames()->first())) {
            abort(422, 'Vous ne pouvez pas désactiver votre propre compte ni changer votre rôle.');
        }
        $user->fill(collect($data)->except(['role', 'password'])->all());
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        $user->syncRoles([$data['role']]);
        AuditLog::record('updated', $user, "Utilisateur modifié : {$user->email}");

        return new UserResource($user->load('roles'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('delete', $user);
        $user->delete();
        AuditLog::record('deleted', $user, "Utilisateur supprimé : {$user->email}");

        return response()->json(['message' => "Utilisateur {$user->name} supprimé."]);
    }

    /** Déverrouille un compte bloqué après 5 échecs. */
    public function unlock(User $user): JsonResponse
    {
        Gate::authorize('update', $user);
        $user->forceFill(['locked_until' => null, 'failed_login_attempts' => 0])->save();
        AuditLog::record('updated', $user, "Compte déverrouillé : {$user->email}");

        return response()->json(['message' => "Compte de {$user->name} déverrouillé."]);
    }

    private function validated(Request $request, ?User $user): array
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->email))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(array_keys(Roles::LABELS))],
            'is_active' => ['boolean'],
            'driver_id' => [Rule::requiredIf($request->input('role') === Roles::DRIVER), 'nullable', 'integer', 'exists:drivers,id', Rule::unique('users', 'driver_id')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(8)->letters()->numbers()],
        ], ['driver_id.required' => 'Un compte Chauffeur doit être lié à une fiche chauffeur.', 'driver_id.unique' => 'Ce chauffeur a déjà un compte.'],
            ['name' => 'nom', 'email' => 'adresse e-mail', 'role' => 'rôle', 'driver_id' => 'chauffeur', 'password' => 'mot de passe']);
        abort_unless($request->user()->can('assignRole', [User::class, $data['role']]), 403, 'Vous ne pouvez pas attribuer le rôle '.$this->label($data['role']).'.');
        if ($data['role'] !== Roles::DRIVER) {
            $data['driver_id'] = null;
        }

        return $data;
    }

    private function label(string $role): string
    {
        return Roles::label($role);
    }
}
