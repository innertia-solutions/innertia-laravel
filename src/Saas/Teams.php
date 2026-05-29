<?php

namespace Innertia\Saas;

use Illuminate\Support\Facades\Route;
use Innertia\Platform\Teams\Routes as TeamsRoutes;

/** CRUD de equipos (RBAC por grupo) bajo el stack privado saas. */
class Teams
{
    public static function routes(string $prefix = 'backoffice/teams'): void
    {
        Route::middleware(Routes::privateMiddleware())->group(function () use ($prefix) {
            TeamsRoutes::register($prefix);
        });
    }
}
