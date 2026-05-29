<?php

namespace Innertia\App;

use Illuminate\Support\Facades\Route;
use Innertia\Files\Directories\Routes as DirectoriesRoutes;

/** Árbol de carpetas — stack privado app. */
class Directories
{
    public static function routes(): void
    {
        Route::middleware(Routes::privateMiddleware())->group(fn () => DirectoriesRoutes::register());
    }
}
