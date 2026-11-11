<?php

namespace Innertia\Passkeys;

use Illuminate\Support\Facades\Route;
use Innertia\Passkeys\Http\Controllers\MyPasskeysController;
use Innertia\Passkeys\Http\Controllers\PasskeyLoginController;
use Innertia\Passkeys\Http\Controllers\PasskeySettingsController;
use Innertia\Passkeys\Http\Middleware\EnsurePasskeysEnabled;

/**
 * Registradores de rutas. Auth\Routes los llama solos. Siempre se
 * registran (las rutas no pueden depender de un valor en BD): el interruptor de
 * plataforma lo aplica EnsurePasskeysEnabled (404) en login y autogestión. Un producto que compone rutas a mano
 * puede llamarlos directo.
 */
class Routes
{
    /** Públicas, dentro del grupo/prefijo de publicRoutes (default 'auth'). */
    public static function login(): void
    {
        Route::get('passkey/status', [PasskeySettingsController::class, 'status'])->middleware('throttle:60,1');

        Route::middleware(EnsurePasskeysEnabled::class)->group(function () {
            Route::post('passkey/login/options', [PasskeyLoginController::class, 'options'])->middleware('throttle:20,1');
            Route::post('passkey/login',         [PasskeyLoginController::class, 'login'])->middleware('throttle:10,1');
        });
    }

    /** Públicas de plataforma (sin tenant). */
    public static function platformLogin(string $prefix = 'platform'): void
    {
        Route::get($prefix . '/auth/passkey/status', [PasskeySettingsController::class, 'status'])->middleware('throttle:60,1');

        Route::middleware(EnsurePasskeysEnabled::class)->group(function () use ($prefix) {
            Route::post($prefix . '/auth/passkey/login/options', [PasskeyLoginController::class, 'options'])->middleware('throttle:20,1');
            Route::post($prefix . '/auth/passkey/login',         [PasskeyLoginController::class, 'platformLogin'])->middleware('throttle:10,1');
        });
    }

    /** Autenticadas, dentro del grupo/prefijo de sessionRoutes (default 'auth'). */
    public static function account(): void
    {
        Route::middleware(EnsurePasskeysEnabled::class)->group(function () {
            Route::get   ('me/passkeys',         [MyPasskeysController::class, 'index']);
            Route::post  ('me/passkeys/options', [MyPasskeysController::class, 'options'])->middleware('throttle:10,1');
            Route::post  ('me/passkeys',         [MyPasskeysController::class, 'store'])->middleware('throttle:10,1');
            Route::patch ('me/passkeys/{id}',    [MyPasskeysController::class, 'update'])->whereUuid('id');
            Route::delete('me/passkeys/{id}',    [MyPasskeysController::class, 'destroy'])->whereUuid('id');
        });
    }

    /**
     * Interruptor de plataforma (GET|PUT {prefix}/passkeys/settings).
     *
     * ATENCIÓN: NO trae auth propia. Debe registrarse DENTRO del grupo platform autenticado
     * del producto (p. ej. \Innertia\Passkeys\Routes::platformSettings('')); la ruta real
     * depende del prefijo de ese grupo. Registrada fuera, cualquiera podría apagar passkeys.
     */
    public static function platformSettings(string $prefix = ''): void
    {
        $base = ltrim($prefix . '/passkeys/settings', '/');

        Route::get($base, [PasskeySettingsController::class, 'show']);
        Route::put($base, [PasskeySettingsController::class, 'update']);
    }
}
