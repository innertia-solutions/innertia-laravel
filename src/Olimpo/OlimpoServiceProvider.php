<?php

namespace Innertia\Olimpo;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Innertia\Olimpo\Http\Middleware\OlimpoAuth;
use Innertia\Olimpo\Listeners\ReportFailedJob;

class OlimpoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config.php', 'olimpo');
    }

    public function boot(): void
    {
        // Publicar config
        $this->publishes([
            __DIR__ . '/config.php' => config_path('olimpo.php'),
        ], 'olimpo-config');

        // Registrar middleware
        $this->app['router']->aliasMiddleware('olimpo.auth', OlimpoAuth::class);

        // Registrar rutas solo si el handler está vinculado
        if ($this->app->bound(\Innertia\Olimpo\Contracts\OlimpoHandler::class)) {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        }

        // Escuchar jobs fallidos si hay OLIMPO_URL configurado
        if (config('olimpo.url') && config('olimpo.key')) {
            Event::listen(JobFailed::class, ReportFailedJob::class);
        }
    }
}
