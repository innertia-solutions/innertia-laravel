<?php

namespace Innertia\App;

use Illuminate\Support\Facades\Route;
use Innertia\Platform\Teams\Routes as TeamsRoutes;

/** CRUD de equipos — stack privado app. */
class Teams
{
    public static function routes(string $prefix = 'backoffice/teams'): void
    {
        Route::middleware(Routes::privateMiddleware())->group(fn () => TeamsRoutes::register($prefix));
    }
}
