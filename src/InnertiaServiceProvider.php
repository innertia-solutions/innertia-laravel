<?php

namespace Innertia;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Innertia\Auth\AuthServiceProvider;
use Innertia\Console\Commands\Make\MakeControllerCommand;
use Innertia\Console\Commands\Make\MakeModelCommand;
use Innertia\Console\Commands\Make\MakeUseCaseCommand;
use Innertia\Console\Commands\SyncPermissionsCommand;
use Innertia\Console\Commands\Tenant\CreateTenantCommand;
use Innertia\Console\Commands\Tenant\DeleteTenantCommand;
use Innertia\Console\Commands\Tenant\ListTenantsCommand;
use Innertia\Console\Commands\Tenant\ShowTenantCommand;
use Innertia\DataTable\DataTableService;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Listeners\DomainEventRouter;
use Innertia\Webhook\WebhookService;
use Innertia\Services\ActivityLogService;
use Innertia\Services\EntityHistoryService;
use Innertia\Services\PermissionsService;
use Innertia\Settings\AppSettingsService;
use Innertia\Settings\SaasSettingsService;

class InnertiaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/innertia.php', 'innertia');

        $this->configureAuth();

        $this->app->singleton(DataTableService::class);
        $this->app->singleton(ActivityLogService::class);
        $this->app->singleton(EntityHistoryService::class);
        $this->app->singleton(PermissionsService::class);

        $isSaas = config('innertia.mode') === 'saas';

        $this->app->singleton(
            AppSettingsService::class,
            $isSaas ? SaasSettingsService::class : AppSettingsService::class
        );

        if ($isSaas) {
            $this->configureTenancy();
        }

        $this->app->register(AuthServiceProvider::class);
        $this->app->singleton(WebhookService::class);
    }

    public function boot(): void
    {
        $isSaas = config('innertia.mode') === 'saas';

        $migrations = [__DIR__ . '/../database/migrations'];

        if (! $isSaas) {
            // Exclude saas-only tables in single-app mode
            $migrations = array_filter(
                glob(__DIR__ . '/../database/migrations/*.php'),
                fn ($f) => ! str_contains($f, 'create_tenants_table')
                        && ! str_contains($f, 'create_tenant_apps_table')
            );
        }

        $this->loadMigrationsFrom($migrations);
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'innertia');

        // Register anonymous Blade components under the <x-innertia::mail.*> namespace
        Blade::anonymousComponentPath(__DIR__ . '/../resources/views/components', 'innertia');

        if ($this->app->runningInConsole()) {
            $commands = [
                SyncPermissionsCommand::class,
                MakeModelCommand::class,
                MakeUseCaseCommand::class,
                MakeControllerCommand::class,
            ];

            if ($isSaas) {
                $commands = array_merge($commands, [
                    ListTenantsCommand::class,
                    ShowTenantCommand::class,
                    CreateTenantCommand::class,
                    DeleteTenantCommand::class,
                ]);
            }

            $this->commands($commands);
        }

        Event::listen(DomainEvent::class, DomainEventRouter::class);

        $this->publishes([
            __DIR__ . '/../config/innertia.php'    => config_path('innertia.php'),
        ], 'innertia-config');

        $this->publishes([
            __DIR__ . '/../resources/views'        => resource_path('views/vendor/innertia'),
        ], 'innertia-mail-views');
    }

    protected function configureAuth(): void
    {
        $userModel = config('innertia.auth.user_model', \App\Models\User::class);

        config([
            'auth.defaults.guard'     => 'api',
            'auth.defaults.passwords' => 'users',

            'auth.guards.api' => [
                'driver'   => 'jwt',
                'provider' => 'users',
            ],

            'auth.providers.users' => [
                'driver' => 'eloquent',
                'model'  => $userModel,
            ],

            'auth.passwords.users' => [
                'provider' => 'users',
                'table'    => 'password_reset_tokens',
                'expire'   => 60,
                'throttle' => 60,
            ],

            'auth.password_timeout' => 10800,
        ]);
    }

    protected function configureTenancy(): void
    {
        $saas     = config('innertia.saas', []);
        $isMulti  = ($saas['db_strategy'] ?? 'single') === 'multi';

        $tenantModel = $saas['tenant_model']
            ?? \Innertia\Models\Tenant::class;

        $bootstrappers = [
            \Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
            \Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
            \Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
        ];

        if ($isMulti) {
            // Switch DB connection per tenant
            array_unshift($bootstrappers, \Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class);
        }

        config([
            'tenancy.tenant_model'    => $tenantModel,
            'tenancy.id_generator'    => null,
            'tenancy.central_domains' => $saas['central_domains'] ?? ['localhost', '127.0.0.1'],

            'tenancy.bootstrappers' => $bootstrappers,

            'tenancy.database' => [
                'central_connection'         => 'pgsql',
                'template_tenant_connection' => null,
                'prefix'                     => $saas['db_prefix'] ?? 'tenant_',
                'suffix'                     => '',
                'managers'                   => [
                    'pgsql' => \Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager::class,
                ],
            ],

            'tenancy.cache' => [
                'tag_base' => 'tenant',
            ],

            'tenancy.filesystem' => [
                'suffix_base'          => 'tenant',
                'disks'                => ['local', 'public'],
                'root_override'        => [
                    'local'  => '%storage_path%/app/',
                    'public' => '%storage_path%/app/public/',
                ],
                'suffix_storage_path'  => true,
                'asset_helper_tenancy' => false,
            ],

            'tenancy.redis' => [
                'prefix_base'          => 'tenant',
                'prefixed_connections' => [],
            ],

            'tenancy.features' => [],
            'tenancy.routes'   => false,

            'tenancy.migration_parameters' => [
                '--force'    => true,
                '--path'     => [database_path('migrations/tenant')],
                '--realpath' => true,
            ],

            'tenancy.seeder_parameters' => [
                '--class' => 'TenantDatabaseSeeder',
            ],
        ]);
    }
}
