<?php

namespace Innertia\Files;

use Illuminate\Support\Facades\Route;

/**
 * Routes helper for files.
 *
 * Two responsibilities:
 *
 * 1. File-serving routes (auto-registered by InnertiaServiceProvider):
 *    Called internally via Routes::registerFileServing().
 *
 * 2. CRUD API routes (opt-in by the consuming app):
 *
 *   // routes/api.private.php
 *   Route::middleware(['auth:api', 'tenant.require'])->group(function () {
 *       \Innertia\Files\Routes::register();
 *   });
 *
 *   Routes::register(prefix, controller) — both args optional.
 */
class Routes
{
    /**
     * Register the file-serving (view + download) routes.
     * Called automatically by InnertiaServiceProvider.
     */
    public static function registerFileServing(): void
    {
        Route::get('/files/{id}/view',     [Http\FileController::class, 'view'])->name('innertia.files.view');
        Route::get('/files/{id}/download', [Http\FileController::class, 'download'])->name('innertia.files.download');
    }

    /**
     * Register the opt-in CRUD API routes for files.
     */
    public static function register(
        string $prefix = 'files',
        string $controller = Http\Controllers\FilesController::class,
    ): void {
        Route::prefix($prefix)->group(function () use ($controller) {
            // /trash routes first (specific paths before {id})
            Route::get  ('trash',        [$controller, 'trash'])->name('files.trash');
            Route::post ('trash/empty',  [$controller, 'emptyTrash'])->name('files.trash.empty');

            Route::get   ('/',            [$controller, 'index'])->name('files.index');
            Route::post  ('/',            [$controller, 'store'])->name('files.store');
            Route::get   ('{id}',         [$controller, 'show'])->name('files.show');
            Route::patch ('{id}',         [$controller, 'update'])->name('files.update');
            Route::delete('{id}',         [$controller, 'destroy'])->name('files.destroy');
            Route::post  ('{id}/restore', [$controller, 'restore'])->name('files.restore');
        });
    }
}

