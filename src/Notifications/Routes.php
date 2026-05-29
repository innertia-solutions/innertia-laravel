<?php

namespace Innertia\Notifications;

use Illuminate\Support\Facades\Route;
use Innertia\Notifications\Http\NotificationsController;

/**
 * Helper opt-in para montar el centro de notificaciones (sin middleware propio).
 */
class Routes
{
    public static function register(
        string $prefix     = 'notifications',
        string $controller = NotificationsController::class,
    ): void {
        Route::prefix($prefix)->group(function () use ($controller) {
            Route::get   ('/',          [$controller, 'index']);
            Route::patch ('/read-all',  [$controller, 'markAllRead']);
            Route::patch ('/{id}/read', [$controller, 'markRead']);
            Route::delete('/',          [$controller, 'destroyRead']);
            Route::delete('/{id}',      [$controller, 'destroy']);
        });
    }
}
