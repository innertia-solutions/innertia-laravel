<?php

namespace Innertia\Saas;

use Illuminate\Support\Facades\Route;
use Innertia\Notifications\Routes as NotificationsRoutes;

/** Centro de notificaciones bajo el stack privado saas. */
class Notifications
{
    public static function routes(string $prefix = 'notifications'): void
    {
        Route::middleware(Routes::privateMiddleware())->group(function () use ($prefix) {
            NotificationsRoutes::register($prefix);
        });
    }
}
