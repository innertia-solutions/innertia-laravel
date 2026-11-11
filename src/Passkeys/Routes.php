<?php

namespace Innertia\Passkeys;

use Illuminate\Support\Facades\Route;
use Innertia\Passkeys\Http\Controllers\MyPasskeysController;
use Innertia\Passkeys\Http\Controllers\PasskeyLoginController;
use Innertia\Passkeys\Support\PasskeyConfig;

/**
 * Registradores de rutas. Auth\Routes los llama solos cuando
 * innertia.passkeys.enabled = true; un producto que compone rutas a mano
 * puede llamarlos directo.
 */
class Routes
{
    /** Públicas, dentro del grupo/prefijo de publicRoutes (default 'auth'). */
    public static function login(): void
    {
        if (! PasskeyConfig::enabled()) {
            return;
        }

        Route::post('passkey/login/options', [PasskeyLoginController::class, 'options'])->middleware('throttle:20,1');
        Route::post('passkey/login',         [PasskeyLoginController::class, 'login'])->middleware('throttle:10,1');
    }

    /** Públicas de plataforma (sin tenant). */
    public static function platformLogin(string $prefix = 'platform'): void
    {
        if (! PasskeyConfig::enabled()) {
            return;
        }

        Route::post($prefix . '/auth/passkey/login/options', [PasskeyLoginController::class, 'options'])->middleware('throttle:20,1');
        Route::post($prefix . '/auth/passkey/login',         [PasskeyLoginController::class, 'platformLogin'])->middleware('throttle:10,1');
    }

    /** Autenticadas, dentro del grupo/prefijo de sessionRoutes (default 'auth'). */
    public static function account(): void
    {
        if (! PasskeyConfig::enabled()) {
            return;
        }

        Route::get   ('me/passkeys',         [MyPasskeysController::class, 'index']);
        Route::post  ('me/passkeys/options', [MyPasskeysController::class, 'options'])->middleware('throttle:10,1');
        Route::post  ('me/passkeys',         [MyPasskeysController::class, 'store'])->middleware('throttle:10,1');
        Route::patch ('me/passkeys/{id}',    [MyPasskeysController::class, 'update']);
        Route::delete('me/passkeys/{id}',    [MyPasskeysController::class, 'destroy']);
    }
}
