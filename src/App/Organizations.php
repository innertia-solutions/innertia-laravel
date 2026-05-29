<?php

namespace Innertia\App;

use Illuminate\Support\Facades\Route;
use Innertia\Platform\Organizations\Routes as OrganizationsRoutes;

/** CRUD de organizaciones — stack privado app. */
class Organizations
{
    public static function routes(string $prefix = 'backoffice/organizations'): void
    {
        Route::middleware(Routes::privateMiddleware())->group(fn () => OrganizationsRoutes::register($prefix));
    }
}
