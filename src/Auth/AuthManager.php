<?php

namespace Innertia\Auth;

class AuthManager
{
    /**
     * Register Innertia auth routes.
     * Call from your routes/api.php:
     *
     *   \Innertia\Auth\AuthManager::routes();
     *   \Innertia\Auth\AuthManager::routes(prefix: 'v1/auth', middleware: ['throttle:10,1']);
     */
    public static function routes(string $prefix = '', array $middleware = []): void
    {
        $router = app('router');

        $router->group([
            'prefix'     => $prefix,
            'middleware' => $middleware,
        ], function () {
            require __DIR__ . '/routes.php';
        });
    }
}
