<?php

namespace Innertia\App;

use Illuminate\Support\Facades\Route;
use Innertia\Backoffice\Routes as BackofficeRoutes;

/** Backoffice genérico (users/roles/permissions/sessions) — stack privado app. */
class Backoffice
{
    public static function routes(string $prefix = 'backoffice'): void
    {
        Route::middleware(Routes::privateMiddleware())->group(fn () => BackofficeRoutes::register($prefix));
    }
}
