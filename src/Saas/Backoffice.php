<?php

namespace Innertia\Saas;

use Illuminate\Support\Facades\Route;
use Innertia\Backoffice\Routes as BackofficeRoutes;

/** Backoffice genérico (users/roles/permissions/sessions) bajo el stack privado saas. */
class Backoffice
{
    public static function routes(string $prefix = 'backoffice'): void
    {
        Route::middleware(Routes::privateMiddleware())->group(function () use ($prefix) {
            BackofficeRoutes::register($prefix);
        });
    }
}
