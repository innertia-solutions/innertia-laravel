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
        string $prefix           = 'files',
        string $controller       = Http\Controllers\FilesController::class,
        string $grantsController = Http\Controllers\FileGrantsController::class,
        string $sharedController = Http\Controllers\SharedFilesController::class,
    ): void {
        Route::prefix($prefix)->group(function () use ($controller, $grantsController, $sharedController) {
            // Specific paths first to avoid {id} capturing these slugs
            Route::get  ('trash',          [$controller, 'trash'])->name('files.trash');
            Route::post ('trash/empty',    [$controller, 'emptyTrash'])->name('files.trash.empty');
            Route::get  ('shared-with-me', [$sharedController, 'index'])->name('files.shared-with-me');

            Route::get   ('/',             [$controller, 'index'])->name('files.index');
            Route::post  ('/',             [$controller, 'store'])->name('files.store');
            Route::get   ('{id}/share-link', [$controller, 'shareLink'])->name('files.share-link');
            Route::get   ('{id}',          [$controller, 'show'])->name('files.show');
            Route::patch ('{id}',          [$controller, 'update'])->name('files.update');
            Route::delete('{id}',          [$controller, 'destroy'])->name('files.destroy');
            Route::post  ('{id}/restore',  [$controller, 'restore'])->name('files.restore');

            // Grants (sharing)
            Route::get   ('{id}/grants',   [$grantsController, 'index'])->name('files.grants.index');
            Route::post  ('{id}/grants',   [$grantsController, 'store'])->name('files.grants.store');
            Route::delete('{id}/grants',   [$grantsController, 'destroy'])->name('files.grants.destroy');
        });
    }

    /**
     * Register the provider connection routes (connect/callback/status/disconnect).
     */
    public static function registerProviderConnections(string $prefix = 'files/providers'): void
    {
        $c = \Innertia\Files\Providers\Http\ProviderConnectionController::class;
        Route::prefix($prefix)->group(function () use ($c) {
            Route::get   ('{provider}/connect',    [$c, 'connect'])->name('innertia.files.providers.connect');
            Route::get   ('{provider}/callback',   [$c, 'callback'])->name('innertia.files.providers.callback');
            Route::get   ('{provider}/status',     [$c, 'status'])->name('innertia.files.providers.status');
            Route::delete('{provider}/connection', [$c, 'disconnect'])->name('innertia.files.providers.disconnect');
        });
    }
}

