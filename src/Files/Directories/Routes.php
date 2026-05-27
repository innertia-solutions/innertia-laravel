<?php

namespace Innertia\Files\Directories;

use Illuminate\Support\Facades\Route;

/**
 * Helper opt-in para montar rutas de directories.
 *
 *   // routes/api.private.php
 *   Route::middleware(['auth:api', 'tenant.require'])->group(function () {
 *       \Innertia\Files\Directories\Routes::register();
 *   });
 */
class Routes
{
    public static function register(
        string $prefix = 'directories',
        string $controller = Http\Controllers\DirectoriesController::class,
    ): void {
        Route::prefix($prefix)->group(function () use ($controller) {
            // Specific paths first to avoid {id} capturing 'trash'
            Route::get   ('trash',        [$controller, 'trash'])->name('directories.trash');
            Route::post  ('trash/empty',  [$controller, 'emptyTrash'])->name('directories.trash.empty');

            Route::get   ('/',            [$controller, 'index'])->name('directories.index');
            Route::post  ('/',            [$controller, 'store'])->name('directories.store');
            Route::get   ('{id}',         [$controller, 'show'])->name('directories.show');
            Route::patch ('{id}',         [$controller, 'update'])->name('directories.update');
            Route::delete('{id}',         [$controller, 'destroy'])->name('directories.destroy');
            Route::get   ('{id}/tree',    [$controller, 'tree'])->name('directories.tree');
            Route::post  ('{id}/restore', [$controller, 'restore'])->name('directories.restore');
        });
    }
}
