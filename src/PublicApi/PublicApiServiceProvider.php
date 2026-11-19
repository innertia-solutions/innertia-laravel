<?php

namespace Innertia\PublicApi;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Innertia\PublicApi\Console\PruneIntegrationLogsCommand;
use Innertia\PublicApi\Docs\RouteDescriptor;
use Innertia\PublicApi\Http\Middleware\AuthenticateApiKey;
use Innertia\Webhooks\Security\DnsHostResolver;
use Innertia\Webhooks\Security\HostResolver;
use Innertia\Webhooks\Security\UrlGuard;

/**
 * Public API + webhooks v2 engine. Everything is additive/opt-in: nothing is exposed until the product mounts
 * \Innertia\PublicApi\Routes::* and uses the `api.key` middleware.
 */
class PublicApiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(HostResolver::class, DnsHostResolver::class);
        $this->app->bind(UrlGuard::class, fn ($app) => new UrlGuard($app->make(HostResolver::class)));
    }

    public function boot(): void
    {
        if (config('innertia.mode') !== 'api') {
            $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations/integrations');
        }

        $this->app['router']->aliasMiddleware('api.key', AuthenticateApiKey::class);

        // Route descriptor: ->apiDoc(['summary' => …, 'scope' => 'calls:read', …]) (stored in the action → route:cache safe).
        if (! Route::hasMacro('apiDoc')) {
            Route::macro('apiDoc', function (array $descriptor) {
                /** @var Route $this */
                $this->action[RouteDescriptor::ACTION_KEY] = $descriptor;

                return $this;
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([PruneIntegrationLogsCommand::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command(PruneIntegrationLogsCommand::class)
                ->dailyAt('04:30')
                ->name('innertia:integrations:prune')
                ->withoutOverlapping(60)
                ->onOneServer();
        });
    }
}
