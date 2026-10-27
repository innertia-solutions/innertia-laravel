<?php

namespace Innertia\Workspace;

use Illuminate\Support\Facades\Route;
use Innertia\Workspace\Http\Controllers\WorkspaceUsersController;

/**
 * Monta el módulo de administración de Workspace (drop-in). El producto lo llama
 * dentro de un grupo con middleware platform:
 *   \Innertia\Workspace\Routes::register('workspace');  // dentro del grupo platform
 */
class Routes
{
    public static function register(?string $prefix = null): void
    {
        $prefix ??= 'platform/workspace';

        Route::prefix($prefix)->group(function () {
            Route::get   ('users',                    [WorkspaceUsersController::class, 'index']);
            Route::post  ('users',                    [WorkspaceUsersController::class, 'store']);
            Route::post  ('users/{userKey}/password', [WorkspaceUsersController::class, 'password']);
            Route::post  ('users/{userKey}/suspend',  [WorkspaceUsersController::class, 'suspend']);
            Route::delete('users/{userKey}',          [WorkspaceUsersController::class, 'destroy']);
        });
    }
}
