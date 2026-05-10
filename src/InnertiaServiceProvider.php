<?php

namespace Innertia;

use Illuminate\Support\ServiceProvider;
use Innertia\DataTable\DataTableService;
use Innertia\Services\ActivityLogService;
use Innertia\Services\EntityHistoryService;
use Innertia\Settings\AppSettingsService;
use Innertia\Settings\SaasSettingsService;

class InnertiaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/innertia.php', 'innertia');

        $this->app->singleton(DataTableService::class);
        $this->app->singleton(ActivityLogService::class);
        $this->app->singleton(EntityHistoryService::class);

        $settingsClass = config('innertia.mode') === 'saas'
            ? SaasSettingsService::class
            : AppSettingsService::class;

        $this->app->singleton(AppSettingsService::class, $settingsClass);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/innertia.php' => config_path('innertia.php'),
        ], 'innertia-config');
    }
}
