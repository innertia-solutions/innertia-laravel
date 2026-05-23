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
     * Override in subclasses to lock in the mode.
     * InnertiaAppProvider  → isSaas: false, isApi: false
     * InnertiaSaasProvider → isSaas: true,  isApi: false
     * InnertiaApiProvider  → isSaas: false, isApi: true
     */
    protected function isSaas(): bool { return config('innertia.mode') === 'saas'; }
    protected function isApi(): bool  { return false; }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/innertia.php', 'innertia');

        // Make the mode authoritative. Valid values: 'app' | 'saas' | 'api'
        $mode = $this->isSaas() ? 'saas' : ($this->isApi() ? 'api' : 'app');
        config(['innertia.mode' => $mode]);

        // TenantContext + InnertiaManager — siempre registrados; no-op en App mode.
        $this->app->singleton(\Innertia\Saas\TenantContext::class);

        if (config('innertia.organizations.enabled')) {
            $this->app->singleton(\Innertia\Platform\Organizations\OrganizationContext::class);
        }

        $this->app->singleton(\Innertia\InnertiaManager::class, function ($app) {
            return new \Innertia\InnertiaManager(
                $app->make(\Innertia\Saas\TenantContext::class),
                $this->isSaas(),
                config('innertia.organizations.enabled')
                    ? $app->make(\Innertia\Platform\Organizations\OrganizationContext::class)
                    : null,
            );
        });

        $this->configureAuth();

        $this->app->singleton(DataTableService::class);
        $this->app->singleton(ExportPipeline::class);
        $this->app->singleton(ActivityLogService::class);
        $this->app->singleton(EntityHistoryService::class);
        $this->app->singleton(PermissionsService::class);
        $this->app->singleton(\Innertia\ApiKeys\Services\ApiKeyService::class);

        $isSaas = $this->isSaas();

        $this->app->singleton(
            AppSettingsService::class,
            $isSaas ? SaasSettingsService::class : AppSettingsService::class
        );

        $this->app->register(AuthServiceProvider::class);
        $this->app->singleton(WebhookService::class);
        $this->app->singleton(\Innertia\Workflow\WorkflowEngine::class);
    }

    public function boot(): void
    {
        $isSaas = $this->isSaas();

        // ── Migrations ────────────────────────────────────────────────────────
        // Each mode has its own clean migration set — no conditionals inside files.
        $mode           = config('innertia.mode');
        $migrationsPath = __DIR__ . '/../database/migrations/' . match($mode) {
            'saas' => 'saas',
            'api'  => 'api',
            default => 'app',   // 'app' mode
        };

        // Load from vendor so migrate works out-of-the-box without publishing.
        $this->loadMigrationsFrom($migrationsPath);

        // Also publishable — `php artisan vendor:publish --tag=innertia-migrations`
        $publishMap = [];
        foreach (glob($migrationsPath . '/*.php') as $f) {
            $publishMap[$f] = database_path('migrations/' . basename($f));
        }
        $this->publishes($publishMap, 'innertia-migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'innertia');

        // Files: rutas de acceso/descarga de archivos (plataforma interna).
        $this->loadRoutesFrom(__DIR__ . '/Files/routes.php');

        // Platform: history, files upload, notifications genéricas.
        $this->loadRoutesFrom(__DIR__ . '/Platform/routes.php');

        // Api mode: rutas Olimpo para gestión de clients.
        if ($mode === 'api') {
            $this->loadRoutesFrom(__DIR__ . '/Api/routes.php');
        }

        // Auth y Backoffice NO se auto-cargan aquí.
        // El proyecto es dueño de routes/api.php (publicado con innertia-routes).
        // Esto evita rutas duplicadas y da control total al developer.

        // ── Blade components ──────────────────────────────────────────────────
        Blade::anonymousComponentPath(__DIR__ . '/../resources/views/components', 'innertia');

        // ── Facade aliases ────────────────────────────────────────────────────
        // Necesario cuando el paquete está en dont-discover (el proyecto registra
        // InnertiaSaasProvider / InnertiaAppProvider manualmente).
        \Illuminate\Foundation\AliasLoader::getInstance()->alias(
            'Innertia',
            \Innertia\Facades\Innertia::class
        );

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

        // ── ForceJsonResponse — global en todas las rutas de la API ──────────
        // Pone Accept: application/json en cada request para que el exception
        // handler y los middlewares de Laravel siempre devuelvan JSON.
        $this->app[\Illuminate\Contracts\Http\Kernel::class]
            ->pushMiddleware(\Innertia\Http\Middleware\ForceJsonResponse::class);

        // ── TraceId — genera X-Trace-Id por request ───────────────────────────
        $this->app[\Illuminate\Contracts\Http\Kernel::class]
            ->pushMiddleware(\Innertia\Http\Middleware\TraceId::class);

        // ── Monolog processor — añade trace_id a todos los logs ──────────────
        $this->app['log']->pushProcessor(function ($record) {
            $traceId = $this->app->bound('trace_id') ? $this->app->make('trace_id') : null;
            if ($traceId) {
                $extra             = $record->extra ?? [];
                $extra['trace_id'] = $traceId;
                return $record->with(extra: $extra);
            }
            return $record;
        });

        // ── Middleware aliases ─────────────────────────────────────────────────
        $router = $this->app['router'];
        $router->aliasMiddleware('app',            AppMiddleware::class);
        $router->aliasMiddleware('role',           RoleMiddleware::class);
        $router->aliasMiddleware('permission',     PermissionMiddleware::class);
        $router->aliasMiddleware('tenant.resolve',    \Innertia\Saas\Middleware\ResolveTenantFromHeader::class);
        $router->aliasMiddleware('tenant.subdomain',  \Innertia\Saas\Middleware\ResolveTenantFromSubdomain::class);
        $router->aliasMiddleware('tenant.require',    \Innertia\Saas\Middleware\RequireTenant::class);
        $router->aliasMiddleware('apikey',            \Innertia\ApiKeys\Middleware\ApiKeyMiddleware::class);

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

        // Stubs de rutas: api.php + api.public.php + api.private.php
        // Se publican una sola vez durante el scaffold; el developer los edita libremente.
        $stub = match($mode) {
            'saas' => 'saas',
            'api'  => 'api',
            default => 'app',
        };
        $routeStubs = [
            __DIR__ . "/../stubs/{$stub}/api.php"         => base_path('routes/api.php'),
            __DIR__ . "/../stubs/{$stub}/api.public.php"  => base_path('routes/api.public.php'),
            __DIR__ . "/../stubs/{$stub}/api.private.php" => base_path('routes/api.private.php'),
        ];

        if (file_exists(__DIR__ . "/../stubs/{$stub}/api.clients.php")) {
            $routeStubs[__DIR__ . "/../stubs/{$stub}/api.clients.php"] = base_path('routes/api.clients.php');
        }

        $this->publishes($routeStubs, 'innertia-routes');
    }

    protected function configureAuth(): void
    {
        $userModel = config('innertia.auth.user_model', \App\Models\User::class);

        config([
            'auth.defaults.guard'     => 'jwt',
            'auth.defaults.passwords' => 'users',

            'auth.guards.jwt' => [
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

}
