<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Innertia\Auth\Services\JwtService;
use Innertia\Exceptions\ForbiddenException;

/**
 * POST platform/auth/login — auth de plataforma SIN contexto ni tenant.
 *
 * Valida credenciales y exige is_platform_admin; emite un JWT con el claim
 * 'platform'. 401 credenciales inválidas · 403 no-platform-admin.
 */
class PlatformAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $model = config('auth.providers.users.model');
        $user  = $model::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Credenciales inválidas.',
                'error'   => 'invalid_credentials',
            ], 401);
        }

        if (! $user->is_platform_admin) {
            throw new ForbiddenException('Esta cuenta no tiene acceso al panel de plataforma.');
        }

        $token = app(JwtService::class)->generateToken($user, ['platform' => true]);

        return response()->json(['token' => $token, 'user' => $user]);
    }
}
