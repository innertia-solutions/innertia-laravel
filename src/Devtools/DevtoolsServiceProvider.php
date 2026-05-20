<?php

namespace Innertia\Devtools;

use Illuminate\Support\ServiceProvider;
use Innertia\Devtools\Http\Middleware\DevtoolsGuard;

class DevtoolsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/innertia.php', 'innertia');
    }

    public function boot(): void
    {
        $this->app['router']->aliasMiddleware('devtools.guard', DevtoolsGuard::class);
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
    }
}
