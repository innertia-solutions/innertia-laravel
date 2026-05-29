<?php

namespace Innertia\Saas;

use Illuminate\Support\Facades\Route;
use Innertia\Platform\Organizations\Routes as OrganizationsRoutes;

/** CRUD de organizaciones (sub-tenant) bajo el stack privado saas. */
class Organizations
{
    public static function routes(string $prefix = 'backoffice/organizations'): void
    {
        Route::middleware(Routes::privateMiddleware())->group(function () use ($prefix) {
            OrganizationsRoutes::register($prefix);
        });
    }
}
