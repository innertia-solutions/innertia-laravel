<?php

namespace Innertia\Auth\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Rechaza cuentas de plataforma (is_platform_admin=true) en el login de tenant.
 *
 * Solo actúa cuando config('innertia.platform.separate_identity') está on y las
 * credenciales son válidas — así una password incorrecta cae al flujo normal de
 * error (anti-enumeración: no revela que el email es de plataforma).
 */
trait RejectsPlatformAccounts
{
    protected function rejectIfPlatformAccount(Request $request): ?JsonResponse
    {
        if (! config('innertia.platform.separate_identity')) {
            return null;
        }

        $email = $request->input('email');
        if (! $email) {
            return null;
        }

        $model = config('auth.providers.users.model');
        $user  = $model::where('email', $email)->first();

        if ($user && $user->is_platform_admin && Hash::check((string) $request->input('password'), $user->password)) {
            $e = new \Innertia\Exceptions\PlatformAccountException();

            return response()->json([
                'message' => $e->getMessage(),
                'error'   => $e->getErrorKey(),
                'errors'  => [],
            ], $e->getStatusCode());
        }

        return null;
    }
}
