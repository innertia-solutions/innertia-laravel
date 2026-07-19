<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Auth\UseCases\PlatformLogin;

/**
 * POST platform/auth/login — auth de plataforma SIN contexto ni tenant.
 *
 * Valida credenciales y exige is_platform_admin; emite un JWT con el claim
 * 'platform'. 422 credenciales inválidas · 403 no-platform-admin.
 */
class PlatformAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $result = (new PlatformLogin(
            email:    $data['email'],
            password: $data['password'],
        ))->execute();

        return response()->json($result);
    }
}
