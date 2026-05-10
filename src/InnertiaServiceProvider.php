<?php

namespace Innertia;

use Illuminate\Support\ServiceProvider;
use Innertia\DataTable\DataTableService;
use Innertia\Services\ActivityLogService;
use Innertia\Services\EntityHistoryService;

class InnertiaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DataTableService::class);
        $this->app->singleton(ActivityLogService::class);
        $this->app->singleton(EntityHistoryService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
