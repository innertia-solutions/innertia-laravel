<?php

namespace Innertia\Saas;

use Illuminate\Support\Facades\Route;
use Innertia\Files\Routes as FilesRoutes;

/** Gestión documental (archivos + grants + papelera) bajo el stack privado saas. */
class Files
{
    public static function routes(): void
    {
        Route::middleware(Routes::privateMiddleware())->group(function () {
            FilesRoutes::register();
        });

        // Vista/descarga inline — el propio helper define su middleware.
        FilesRoutes::registerFileServing();
    }
}
