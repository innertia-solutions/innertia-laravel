<?php

namespace Innertia\Auth\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Innertia\Exceptions\ForbiddenException;

/**
 * POST platform/auth/password — la cuenta de plataforma autenticada define su
 * contraseña nueva. Paso obligatorio tras entrar con una clave temporal
 * (force_password_change); también sirve para cambiarla a voluntad.
 */
class PlatformPasswordController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user?->is_platform_admin)) {
            throw new ForbiddenException('Acceso restringido al panel de plataforma.');
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
        ], [
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        if (Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'La contraseña nueva debe ser distinta a la actual.',
                'errors'  => ['password' => ['La contraseña nueva debe ser distinta a la actual.']],
            ], 422);
        }

        $user->forceFill([
            'password'              => Hash::make($data['password']),
            'force_password_change' => false,
        ])->save();

        return response()->json(['user' => $user->fresh()]);
    }
}
