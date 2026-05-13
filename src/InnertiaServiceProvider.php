<?php

namespace Innertia;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Innertia\Auth\AuthServiceProvider;
use Innertia\Auth\Middleware\AppMiddleware;
use Innertia\Auth\Middleware\PermissionMiddleware;
use Innertia\Auth\Middleware\RoleMiddleware;
use Innertia\Console\Commands\Make\MakeControllerCommand;
use Innertia\Console\Commands\Make\MakeModelCommand;
use Innertia\Console\Commands\Make\MakeUseCaseCommand;
use Innertia\Console\Commands\SyncPermissionsCommand;
use Innertia\Saas\Console\Commands\CreateTenantCommand;
use Innertia\Saas\Console\Commands\DeleteTenantCommand;
use Innertia\Saas\Console\Commands\ListTenantsCommand;
use Innertia\Saas\Console\Commands\ShowTenantCommand;
use Innertia\DataTable\DataTableService;
use Innertia\Exports\ExportPipeline;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Platform\Listeners\DomainEventRouter;
use Innertia\Webhooks\WebhookService;
use Innertia\Auth\RBAC\Models\EntityPermission;
use Innertia\Platform\Services\ActivityLogService;
use Innertia\Platform\Services\EntityHistoryService;
use Innertia\Auth\RBAC\Services\PermissionsService;
use Innertia\Settings\AppSettingsService;
use Innertia\Saas\Settings\SaasSettingsService;

class InnertiaServiceProvider extends ServiceProvider
{
    /**
     * Override in InnertiaAppProvider (false) or InnertiaSaasProvider (true).
     * Falls back to config('innertia.mode') when used directly.
     */
    protected function isSaas(): bool
    {
        return config('innertia.mode') === 'saas';
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/innertia.php', 'innertia');

        // Make the mode authoritative — concrete subclasses lock it in at provider registration.
        config(['innertia.mode' => $this->isSaas() ? 'saas' : 'single']);

        $this->configureAuth();

        $this->app->singleton(DataTableService::class);
        $this->app->singleton(ExportPipeline::class);
        $this->app->singleton(ActivityLogService::class);
        $this->app->singleton(EntityHistoryService::class);
        $this->app->singleton(PermissionsService::class);

        $isSaas = $this->isSaas();

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
        $isSaas = $this->isSaas();

        // ── Migrations ────────────────────────────────────────────────────────
        // Each mode has its own clean migration set — no conditionals inside files.
        $migrationsPath = __DIR__ . '/../database/migrations/' . ($isSaas ? 'saas' : 'single');

        // Load from vendor so migrate works out-of-the-box without publishing.
        $this->loadMigrationsFrom($migrationsPath);

        // Also publishable — `php artisan vendor:publish --tag=innertia-migrations`
        // copies them into database/migrations/ for inspection or customization.
        $publishMap = [];
        foreach (glob($migrationsPath . '/*.php') as $f) {
            $publishMap[$f] = database_path('migrations/' . basename($f));
        }
        $this->publishes($publishMap, 'innertia-migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'innertia');
        $this->loadRoutesFrom(__DIR__ . '/Files/routes.php');

        if (config('innertia.backoffice.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/Backoffice/routes.php');
        }

        // ── Blade components ──────────────────────────────────────────────────
        Blade::anonymousComponentPath(__DIR__ . '/../resources/views/components', 'innertia');

        // ── Gate: check HasRoles::hasPermission() for all Gate checks ─────────
        // Returns null (falls through) if the user model doesn't use HasRoles.
        // This means the Gate keeps working for standard policy-based checks.
        Gate::before(function (Authenticatable $user, string $ability) {
            if (method_exists($user, 'hasPermission')) {
                if ($user->hasPermission($ability)) {
                    return true;
                }

                // Check optional hierarchy (config('innertia.permissions_hierarchy'))
                $service = app(PermissionsService::class);
                if ($service->check($user, $ability)) {
                    return true;
                }
            }

            return null; // fall through to policies
        });

        // ── Middleware aliases ─────────────────────────────────────────────────
        $router = $this->app['router'];
        $router->aliasMiddleware('app',        AppMiddleware::class);
        $router->aliasMiddleware('role',       RoleMiddleware::class);
        $router->aliasMiddleware('permission', PermissionMiddleware::class);

        // ── Console commands ──────────────────────────────────────────────────
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

        // ── Events ────────────────────────────────────────────────────────────
        Event::listen(DomainEvent::class, DomainEventRouter::class);

        // ── Publishables ──────────────────────────────────────────────────────
        $this->publishes([
            __DIR__ . '/../config/innertia.php' => config_path('innertia.php'),
        ], 'innertia-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/innertia'),
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
        $saas    = config('innertia.saas', []);
        $isMulti = ($saas['db_strategy'] ?? 'single') === 'multi';

        $tenantModel = $saas['tenant_model']
            ?? \Innertia\Saas\Models\Tenant::class;

        $bootstrappers = [
            \Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
            \Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
            \Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
        ];

        if ($isMulti) {
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
