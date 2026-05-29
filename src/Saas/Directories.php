<?php

namespace Innertia\Saas;

use Illuminate\Support\Facades\Route;
use Innertia\Files\Directories\Routes as DirectoriesRoutes;

/** Árbol de carpetas (materialized path + trash) bajo el stack privado saas. */
class Directories
{
    public static function routes(): void
    {
        Route::middleware(Routes::privateMiddleware())->group(function () {
            DirectoriesRoutes::register();
        });
    }
}
