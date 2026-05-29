<?php

namespace Innertia\App;

use Illuminate\Support\Facades\Route;
use Innertia\Files\Routes as FilesRoutes;

/** Gestión documental — stack privado app. */
class Files
{
    public static function routes(): void
    {
        Route::middleware(Routes::privateMiddleware())->group(fn () => FilesRoutes::register());
        FilesRoutes::registerFileServing();
    }
}
