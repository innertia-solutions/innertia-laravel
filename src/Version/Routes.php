<?php

namespace Innertia\Version;

use Illuminate\Support\Facades\Route;
use Innertia\Version\Http\Controllers\VersionController;

/**
 * Endpoint de versiones en dos niveles.
 *
 *   // routes/api.public.php
 *   \Innertia\Version\Routes::public();                 // GET /version
 *
 *   // dentro del grupo platform (auth + platform admin) del producto
 *   \Innertia\Version\Routes::platform('');             // GET platform/version
 *
 * El detalle expone versiones de runtime y dependencias: NUNCA montarlo fuera de un
 * grupo protegido (facilita fingerprinting / búsqueda de CVEs).
 */
class Routes
{
    public static function public(string $uri = 'version'): void
    {
        Route::get($uri, [VersionController::class, 'show'])->middleware('throttle:60,1');
    }

    /** $prefix: null → 'platform' (grupo sin prefijo); '' cuando el grupo ya prefija 'platform'. */
    public static function platform(?string $prefix = null): void
    {
        $prefix ??= 'platform';
        Route::get(ltrim($prefix . '/version', '/'), [VersionController::class, 'details']);
    }
}
