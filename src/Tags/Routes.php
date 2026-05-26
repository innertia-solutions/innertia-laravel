<?php

namespace Innertia\Tags;

use Illuminate\Support\Facades\Route;

/**
 * Helper opt-in para montar rutas CRUD de tags + attach/detach por entidad.
 *
 *   // routes/api.private.php
 *   Route::middleware(['auth:api', 'tenant.require'])->group(function () {
 *       \Innertia\Tags\Routes::register();
 *   });
 *
 * Taggables routes (attach/detach per entity) se agregan en una segunda tarea.
 */
class Routes
{
    public static function register(
        string $prefix = 'tags',
        string $controller = Http\Controllers\TagsController::class,
    ): void {
        Route::prefix($prefix)->group(function () use ($controller) {
            Route::get   ('popular',  [$controller, 'popular'])->name('tags.popular');
            Route::get   ('/',        [$controller, 'index'])->name('tags.index');
            Route::post  ('/',        [$controller, 'store'])->name('tags.store');
            Route::get   ('{id}',     [$controller, 'show'])->name('tags.show');
            Route::patch ('{id}',     [$controller, 'update'])->name('tags.update');
            Route::delete('{id}',     [$controller, 'destroy'])->name('tags.destroy');
        });
    }
}
