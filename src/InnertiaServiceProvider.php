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

        $isSaas = config('innertia.mode') === 'saas';

        $this->app->singleton(
            AppSettingsService::class,
            $isSaas ? SaasSettingsService::class : AppSettingsService::class
        );

        if ($isSaas) {
            $this->configureTenancy();
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/innertia.php' => config_path('innertia.php'),
        ], 'innertia-config');
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
