<?php

namespace Innertia\Platform\Organizations;

use Illuminate\Support\Facades\Route;
use Innertia\Platform\Organizations\Http\Controllers\OrganizationsController;

/**
 * Helper opt-in para montar las rutas CRUD estándar de Organizations.
 *
 *   // routes/api.private.php
 *   Route::middleware(['auth:api', 'tenant.require'])->group(function () {
 *       \Innertia\Platform\Organizations\Routes::register();
 *   });
 *
 * Para customizar el prefijo o el controller:
 *
 *   Routes::register(prefix: 'admin/organizations', controller: \App\Http\OrgsController::class);
 *
 * Si la app prefiere definir sus propias rutas, simplemente no llama a register().
 */
class Routes
{
    public static function register(
        string $prefix = 'organizations',
        string $controller = OrganizationsController::class,
    ): void {
        Route::prefix($prefix)->group(function () use ($controller) {
            Route::get   ('/',          [$controller, 'index'])->name('organizations.index');
            Route::post  ('/',          [$controller, 'store'])->name('organizations.store');
            Route::get   ('{id}',       [$controller, 'show'])->name('organizations.show');
            Route::put   ('{id}',       [$controller, 'update'])->name('organizations.update');
            Route::delete('{id}',       [$controller, 'destroy'])->name('organizations.destroy');
        });
    }
}
