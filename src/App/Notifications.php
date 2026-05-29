<?php

namespace Innertia\App;

use Illuminate\Support\Facades\Route;
use Innertia\Notifications\Routes as NotificationsRoutes;

/** Centro de notificaciones — stack privado app. */
class Notifications
{
    public static function routes(string $prefix = 'notifications'): void
    {
        Route::middleware(Routes::privateMiddleware())->group(fn () => NotificationsRoutes::register($prefix));
    }
}
