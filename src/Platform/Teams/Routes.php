<?php

namespace Innertia\Platform\Teams;

use Illuminate\Support\Facades\Route;
use Innertia\Platform\Teams\Http\Controllers\TeamsController;

/**
 * Helper opt-in para montar las rutas CRUD estándar de Teams.
 *
 *   // routes/api.private.php
 *   Route::middleware(['auth:api', 'tenant.require'])->group(function () {
 *       \Innertia\Platform\Teams\Routes::register();
 *   });
 *
 * Las apps que necesiten customizar pueden:
 *   - cambiar el prefix:      Routes::register('admin/teams')
 *   - cambiar el controller:  Routes::register('teams', \App\Http\TeamsController::class)
 *   - no llamar register() y definir sus propias rutas.
 */
class Routes
{
    public static function register(
        string $prefix = 'teams',
        string $controller = TeamsController::class,
    ): void {
        Route::prefix($prefix)->group(function () use ($controller) {
            Route::get   ('/',              [$controller, 'index'])->name('teams.index');
            Route::post  ('/',              [$controller, 'store'])->name('teams.store');
            Route::get   ('{id}',           [$controller, 'show'])->name('teams.show');
            Route::put   ('{id}',           [$controller, 'update'])->name('teams.update');
            Route::delete('{id}',           [$controller, 'destroy'])->name('teams.destroy');
            Route::put   ('{id}/members',   [$controller, 'syncMembers'])->name('teams.syncMembers');
        });
    }
}
