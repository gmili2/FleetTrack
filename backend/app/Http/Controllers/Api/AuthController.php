<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Authentification SPA par cookie de session (Sanctum, garde « web »). */
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse|UserResource
    {
        $email = mb_strtolower($request->string('email'));
        $user = User::where('email', $email)->first();

        if ($user?->isLocked()) {
            return $this->lockedResponse($user);
        }

        if (! $user || ! Auth::guard('web')->validate(['email' => $email, 'password' => $request->string('password')])) {
            $user?->registerFailedLogin();
            if ($user?->isLocked()) {
                return $this->lockedResponse($user);
            }
            $left = $user ? User::MAX_LOGIN_ATTEMPTS - $user->failed_login_attempts : null;

            throw ValidationException::withMessages([
                'email' => $left !== null && $left <= 2
                    ? "Identifiants incorrects. Encore $left essai(s) avant le verrouillage du compte."
                    : 'Identifiants incorrects.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Ce compte est désactivé. Contactez un administrateur.']);
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->registerSuccessfulLogin($request->ip());

        return new UserResource($user);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Déconnecté.']);
    }

    private function lockedResponse(User $user): JsonResponse
    {
        $minutes = max(1, (int) ceil(now()->diffInSeconds($user->locked_until) / 60));

        return response()->json([
            'message' => "Compte verrouillé après 5 tentatives échouées. Réessayez dans $minutes minute(s).",
            'locked_until' => $user->locked_until->toIso8601String(),
        ], 423);
    }
}
