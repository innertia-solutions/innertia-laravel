# Innertia Tenant Manager — Reemplazo de stancl/tenancy

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar la dependencia de `stancl/tenancy` por una implementación propia basada en `Innertia::tenant()`, propagación en HTTP/Queue/CLI, y hacer no-op todo en modo App.

**Architecture:** Un `TenantContext` singleton mantiene el tenant activo en memoria. `InnertiaManager` envuelve ese contexto y expone la API pública (`tenant()`, `activate()`, `deactivate()`). Todo es no-op cuando `config('innertia.mode') !== 'saas'`. Los UseCases capturan el `tenant_key` en construcción y lo restauran al ejecutarse en queue.

**Tech Stack:** PHP 8.2+, Laravel 11+, Pest 3, Eloquent, Laravel Facades.

---

## Mapa de archivos

### Nuevos
- `src/Saas/TenantContext.php` — singleton; mantiene `?Tenant` activo en RAM
- `src/InnertiaManager.php` — lógica pública: `tenant()`, `activate()`, `deactivate()`; mode-aware
- `src/Facades/Innertia.php` — facade Laravel apuntando a `InnertiaManager`
- `src/Saas/Middleware/ResolveTenantFromHeader.php` — lee `X-Tenant` y llama `Innertia::activate()`
- `src/Saas/Middleware/RequireTenant.php` — 401 si no hay tenant activo

### Modificados
- `src/Saas/Models/Tenant.php` — quitar todas las referencias stancl; Eloquent puro
- `src/Platform/Contracts/UseCase.php` — capturar `$tenantKey` en constructor; restaurar en queue `handle()`
- `src/Platform/Traits/HasTenant.php` — reemplazar `function_exists('tenant') && tenant()` → `Innertia::tenant()`
- `src/Auth/RBAC/Models/Permission.php` — misma sustitución
- `src/Auth/RBAC/Models/EntityPermission.php` — misma sustitución
- `src/Auth/RBAC/Traits/HasRoles.php` — misma sustitución
- `src/Auth/RBAC/Traits/HasApps.php` — misma sustitución
- `src/Auth/RBAC/UseCases/CreateRole.php` — misma sustitución
- `src/Auth/RBAC/Services/PermissionsService.php` — misma sustitución (×2)
- `src/Platform/Traits/HasEntityPermissions.php` — misma sustitución
- `src/Backoffice/Http/Controllers/RolesController.php` — misma sustitución
- `src/InnertiaServiceProvider.php` — eliminar `configureTenancy()`, registrar nuevos singletons y middlewares
- `src/Saas/Console/Commands/CreateTenantCommand.php` — quitar `$tenant->domains()->create()`
- `src/Saas/Console/Commands/ShowTenantCommand.php` — quitar bloque `$tenant->domains`
- `composer.json` — quitar `suggest: stancl/tenancy`; agregar alias `Innertia` en extra
- `innertia-setup/templates/saas/backend/composer.json` — quitar `stancl/tenancy`

### Stubs (route split)
- `stubs/app/api.php` — reemplazar por versión que require public + private
- `stubs/app/api.public.php` — nuevo; rutas sin auth
- `stubs/app/api.private.php` — nuevo; rutas con auth
- `stubs/saas/api.php` — igual, versión saas
- `stubs/saas/api.public.php` — nuevo; rutas sin auth (+ tenant header resuelto por middleware)
- `stubs/saas/api.private.php` — nuevo; rutas con auth

---

## Task 1: TenantContext singleton

**Files:**
- Create: `src/Saas/TenantContext.php`
- Test: `tests/Saas/TenantContextTest.php`

- [ ] **Step 1: Crear el test**

```php
<?php
// tests/Saas/TenantContextTest.php

use Innertia\Saas\TenantContext;
use Innertia\Saas\Models\Tenant;

it('starts with no active tenant', function () {
    $ctx = new TenantContext();
    expect($ctx->get())->toBeNull();
});

it('can set and get a tenant', function () {
    $ctx    = new TenantContext();
    $tenant = new Tenant(['key' => 'acme', 'name' => 'Acme']);

    $ctx->set($tenant);

    expect($ctx->get())->toBe($tenant);
});

it('can clear the active tenant', function () {
    $ctx    = new TenantContext();
    $tenant = new Tenant(['key' => 'acme', 'name' => 'Acme']);

    $ctx->set($tenant);
    $ctx->clear();

    expect($ctx->get())->toBeNull();
});
```

- [ ] **Step 2: Verificar que falla**

```
cd /Users/guillermofarias/Sites/inertia/innertia-laravel
./vendor/bin/pest tests/Saas/TenantContextTest.php
```

Expected: `FAIL — class TenantContext not found`

- [ ] **Step 3: Implementar TenantContext**

```php
<?php
// src/Saas/TenantContext.php

namespace Innertia\Saas;

use Innertia\Saas\Models\Tenant;

/**
 * Mantiene el Tenant activo para el request/job/command actual.
 * Se registra como singleton en InnertiaServiceProvider.
 */
class TenantContext
{
    private ?Tenant $current = null;

    public function set(Tenant $tenant): void
    {
        $this->current = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->current;
    }

    public function clear(): void
    {
        $this->current = null;
    }
}
```

- [ ] **Step 4: Verificar que pasa**

```
./vendor/bin/pest tests/Saas/TenantContextTest.php
```

Expected: `3 tests PASSED`

- [ ] **Step 5: Commit**

```bash
git add src/Saas/TenantContext.php tests/Saas/TenantContextTest.php
git commit -m "feat: add TenantContext singleton"
```

---

## Task 2: InnertiaManager + Innertia facade

**Files:**
- Create: `src/InnertiaManager.php`
- Create: `src/Facades/Innertia.php`
- Test: `tests/InnertiaManagerTest.php`

- [ ] **Step 1: Crear el test**

```php
<?php
// tests/InnertiaManagerTest.php

use Innertia\InnertiaManager;
use Innertia\Saas\TenantContext;
use Innertia\Saas\Models\Tenant;
use Innertia\Exceptions\NotFoundException;

function makeManager(bool $isSaas = true): InnertiaManager
{
    $ctx = new TenantContext();
    return new InnertiaManager($ctx, $isSaas);
}

// ── App mode (no-ops) ────────────────────────────────────────────────────────

it('returns null from tenant() in app mode', function () {
    $mgr = makeManager(false);
    expect($mgr->tenant())->toBeNull();
});

it('returns null from tenant(key) in app mode', function () {
    $mgr = makeManager(false);
    expect($mgr->tenant('anything'))->toBeNull();
});

it('activate() is a no-op in app mode', function () {
    $mgr = makeManager(false);
    expect($mgr->activate('anything'))->toBeNull();
});

it('deactivate() is a no-op in app mode', function () {
    $mgr = makeManager(false);
    $mgr->deactivate(); // should not throw
    expect(true)->toBeTrue();
});

// ── SaaS mode ────────────────────────────────────────────────────────────────

it('returns null when no tenant is active in saas mode', function () {
    $mgr = makeManager(true);
    expect($mgr->tenant())->toBeNull();
});

it('returns the active tenant when set', function () {
    $ctx    = new TenantContext();
    $tenant = new Tenant(['key' => 'acme', 'name' => 'Acme']);
    $ctx->set($tenant);

    $mgr = new InnertiaManager($ctx, true);

    expect($mgr->tenant())->toBe($tenant);
});

it('deactivate() clears the active tenant', function () {
    $ctx    = new TenantContext();
    $tenant = new Tenant(['key' => 'acme', 'name' => 'Acme']);
    $ctx->set($tenant);

    $mgr = new InnertiaManager($ctx, true);
    $mgr->deactivate();

    expect($mgr->tenant())->toBeNull();
});
```

- [ ] **Step 2: Verificar que falla**

```
./vendor/bin/pest tests/InnertiaManagerTest.php
```

Expected: `FAIL — class InnertiaManager not found`

- [ ] **Step 3: Implementar InnertiaManager**

```php
<?php
// src/InnertiaManager.php

namespace Innertia;

use Innertia\Exceptions\NotFoundException;
use Innertia\Saas\Models\Tenant;
use Innertia\Saas\TenantContext;

/**
 * API pública para tenant management.
 *
 *   Innertia::tenant()          → Tenant|null  (tenant activo en runtime)
 *   Innertia::tenant('acme')    → Tenant|null  (busca por key; null en App mode)
 *   Innertia::activate('acme')  → Tenant|null  (busca + setea; null en App mode)
 *   Innertia::deactivate()      → void         (limpia contexto; no-op en App mode)
 *
 * Todos los métodos son no-op / devuelven null en App mode (isSaas = false).
 */
class InnertiaManager
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly bool          $isSaas,
    ) {}

    /**
     * Sin argumento: devuelve el tenant activo en runtime.
     * Con argumento: busca el tenant por key en la BD (nunca setea el contexto).
     */
    public function tenant(?string $key = null): ?Tenant
    {
        if (! $this->isSaas) {
            return null;
        }

        if ($key === null) {
            return $this->context->get();
        }

        return $this->findByKey($key);
    }

    /**
     * Busca el tenant por key y lo setea como activo en el contexto.
     * Devuelve null en App mode; devuelve el Tenant si lo encuentra.
     *
     * @throws NotFoundException si el tenant no existe (solo en SaaS mode)
     */
    public function activate(string $key): ?Tenant
    {
        if (! $this->isSaas) {
            return null;
        }

        $tenant = $this->findByKey($key);

        if (! $tenant) {
            throw new NotFoundException("Tenant \"{$key}\" not found.");
        }

        $this->context->set($tenant);

        return $tenant;
    }

    /**
     * Limpia el tenant activo del contexto. No-op en App mode.
     */
    public function deactivate(): void
    {
        if (! $this->isSaas) {
            return;
        }

        $this->context->clear();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function findByKey(string $key): ?Tenant
    {
        /** @var class-string<Tenant> $model */
        $model = config('innertia.saas.tenant_model', Tenant::class);

        return $model::findByKey($key);
    }
}
```

- [ ] **Step 4: Implementar la facade**

```php
<?php
// src/Facades/Innertia.php

namespace Innertia\Facades;

use Illuminate\Support\Facades\Facade;
use Innertia\InnertiaManager;
use Innertia\Saas\Models\Tenant;

/**
 * @method static Tenant|null tenant(?string $key = null)
 * @method static Tenant|null activate(string $key)
 * @method static void        deactivate()
 *
 * @see InnertiaManager
 */
class Innertia extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return InnertiaManager::class;
    }
}
```

- [ ] **Step 5: Verificar que pasa**

```
./vendor/bin/pest tests/InnertiaManagerTest.php
```

Expected: `8 tests PASSED`

- [ ] **Step 6: Commit**

```bash
git add src/InnertiaManager.php src/Facades/Innertia.php tests/InnertiaManagerTest.php
git commit -m "feat: add InnertiaManager and Innertia facade"
```

---

## Task 3: Registrar TenantContext + InnertiaManager en el ServiceProvider

**Files:**
- Modify: `src/InnertiaServiceProvider.php`
- Modify: `composer.json`

- [ ] **Step 1: Registrar los singletons en `register()`**

En `src/InnertiaServiceProvider.php`, método `register()`, **después** de `$this->mergeConfigFrom(...)` y **antes** de `$this->configureAuth()`, agregar:

```php
// Registrar TenantContext — siempre (necesario aunque no sea saas, para el singleton vacío)
$this->app->singleton(\Innertia\Saas\TenantContext::class);

// Registrar InnertiaManager — conoce el modo en tiempo de register
$this->app->singleton(\Innertia\InnertiaManager::class, function ($app) {
    return new \Innertia\InnertiaManager(
        $app->make(\Innertia\Saas\TenantContext::class),
        $this->isSaas(),
    );
});
```

El método `register()` queda así (orden completo):

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__ . '/../config/innertia.php', 'innertia');

    config(['innertia.mode' => $this->isSaas() ? 'saas' : 'single']);

    // TenantContext + InnertiaManager
    $this->app->singleton(\Innertia\Saas\TenantContext::class);

    $this->app->singleton(\Innertia\InnertiaManager::class, function ($app) {
        return new \Innertia\InnertiaManager(
            $app->make(\Innertia\Saas\TenantContext::class),
            $this->isSaas(),
        );
    });

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
```

- [ ] **Step 2: Agregar el alias de facade en `boot()`**

En `src/InnertiaServiceProvider.php`, dentro del método `boot()`, al final del bloque de Blade:

```php
// ── Facade aliases ────────────────────────────────────────────────────────
\Illuminate\Foundation\AliasLoader::getInstance()->alias(
    'Innertia',
    \Innertia\Facades\Innertia::class
);
```

- [ ] **Step 3: Agregar alias en `composer.json` extra**

En `composer.json`, sección `"extra" > "laravel" > "aliases"`, agregar:

```json
"Innertia": "Innertia\\Facades\\Innertia"
```

El bloque completo queda:

```json
"aliases": {
    "DataTable": "Innertia\\Facades\\DataTable",
    "ActivityLogger": "Innertia\\Facades\\ActivityLogger",
    "EntityHistory": "Innertia\\Facades\\EntityHistory",
    "Settings": "Innertia\\Facades\\Settings",
    "Innertia": "Innertia\\Facades\\Innertia"
}
```

- [ ] **Step 4: Verificar tests existentes**

```
./vendor/bin/pest
```

Expected: todos los tests pasan sin regresiones.

- [ ] **Step 5: Commit**

```bash
git add src/InnertiaServiceProvider.php composer.json
git commit -m "feat: register TenantContext and InnertiaManager singletons, add Innertia facade alias"
```

---

## Task 4: Limpiar Tenant model (quitar stancl)

**Files:**
- Modify: `src/Saas/Models/Tenant.php`

- [ ] **Step 1: Reemplazar el contenido del modelo**

El modelo actual implementa `TenantContract`, usa `HasDomains` y dispara eventos de stancl. Reemplazar por Eloquent puro:

```php
<?php
// src/Saas/Models/Tenant.php

namespace Innertia\Saas\Models;

use Illuminate\Database\Eloquent\Model;
use Innertia\Auth\RBAC\Traits\HasApps;

/**
 * Tenant model — single-DB multitenancy.
 *
 * id   — bigInteger auto-increment (PK interna, nunca expuesta en API)
 * key  — string slug; identificador externo (header X-Tenant)
 * name — texto libre; nombre del tenant
 *
 * Extender en la app:
 *   class Tenant extends \Innertia\Saas\Models\Tenant { ... }
 * Y configurar: config('innertia.saas.tenant_model') = App\Models\Tenant::class
 */
class Tenant extends Model
{
    use HasApps;

    protected $fillable = [
        'key',
        'name',
        'status',
        'trial_ends_at',
        'configs',
        'data',
    ];

    protected $casts = [
        'configs'       => 'array',
        'data'          => 'array',
        'trial_ends_at' => 'datetime',
    ];

    // ── Route model binding ───────────────────────────────────────────────────

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function findByKey(string $key): ?static
    {
        return static::where('key', $key)->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOnTrial(): bool
    {
        return $this->status === 'trial';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'inactive';
    }

    public function isTrialExpired(): bool
    {
        return $this->status === 'trial'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }
}
```

- [ ] **Step 2: Limpiar CreateTenantCommand** (quitaba `$tenant->domains()->create(...)`)

En `src/Saas/Console/Commands/CreateTenantCommand.php`, eliminar:

```php
// Quitar este bloque completo:
if ($domain = $this->option('domain')) {
    $tenant->domains()->create(['domain' => $domain]);
}
```

Y quitar la opción `{--domain=}` de la firma:

```php
protected $signature = 'tenant:create
    {key  : Unique slug identifier for the tenant (e.g. acme)}
    {name : Display name for the tenant (e.g. "Acme Corp")}
    {--status=trial       : Initial status (trial, active, inactive)}
    {--trial-days=14      : Days until trial expires (only when status=trial)}';
```

- [ ] **Step 3: Limpiar ShowTenantCommand** (quitaba el bloque de dominios)

En `src/Saas/Console/Commands/ShowTenantCommand.php`, eliminar:

```php
// Quitar este bloque completo:
if ($tenant->domains && $tenant->domains->isNotEmpty()) {
    $this->newLine();
    $this->line('<fg=gray>Domains:</>');
    $this->table(['Domain'], $tenant->domains->map(fn ($d) => [$d->domain])->toArray());
}
```

- [ ] **Step 4: Eliminar `configureTenancy()` del ServiceProvider**

En `src/InnertiaServiceProvider.php`:

1. En `register()`, eliminar la llamada `$this->configureTenancy()` y el bloque `if ($isSaas) { $this->configureTenancy(); }`.
2. Eliminar el método completo `protected function configureTenancy(): void { ... }` (líneas 200–268).
3. Eliminar los imports de stancl que quedaron obsoletos (no hay imports directos en el provider; los Stancl\ son referencias FQCN dentro del método que se elimina — se van solos).

- [ ] **Step 5: Quitar `suggest: stancl/tenancy` de composer.json**

En `composer.json`, eliminar la sección entera `"suggest"`:

```json
// Quitar:
"suggest": {
    "stancl/tenancy": "Required when using mode = saas"
},
```

- [ ] **Step 6: Tests**

```
./vendor/bin/pest
```

Expected: todos los tests pasan.

- [ ] **Step 7: Commit**

```bash
git add src/Saas/Models/Tenant.php \
        src/Saas/Console/Commands/CreateTenantCommand.php \
        src/Saas/Console/Commands/ShowTenantCommand.php \
        src/InnertiaServiceProvider.php \
        composer.json
git commit -m "feat: remove stancl/tenancy — Tenant model es Eloquent puro"
```

---

## Task 5: ResolveTenantFromHeader + RequireTenant middlewares

**Files:**
- Create: `src/Saas/Middleware/ResolveTenantFromHeader.php`
- Create: `src/Saas/Middleware/RequireTenant.php`
- Modify: `src/InnertiaServiceProvider.php` (registrar aliases)

- [ ] **Step 1: Crear ResolveTenantFromHeader**

```php
<?php
// src/Saas/Middleware/ResolveTenantFromHeader.php

namespace Innertia\Saas\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Exceptions\NotFoundException;
use Innertia\Facades\Innertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lee el header X-Tenant y activa el tenant correspondiente en el contexto.
 *
 * Si el header no está presente, pasa sin activar ningún tenant
 * (útil para rutas públicas que no requieren tenant).
 *
 * Si el header está presente pero el tenant no existe, devuelve 401.
 *
 * Usar junto con RequireTenant cuando se necesite garantizar que hay un tenant activo.
 */
class ResolveTenantFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Tenant');

        if ($key) {
            try {
                Innertia::activate($key);
            } catch (NotFoundException) {
                return response()->json(['message' => 'Tenant not found.'], 401);
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 2: Crear RequireTenant**

```php
<?php
// src/Saas/Middleware/RequireTenant.php

namespace Innertia\Saas\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Facades\Innertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garantiza que hay un tenant activo en el contexto.
 *
 * Usar en rutas que exigen contexto de tenant (siempre después de
 * ResolveTenantFromHeader en la pila de middlewares).
 */
class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Innertia::tenant()) {
            return response()->json(['message' => 'Tenant required.'], 401);
        }

        return $next($request);
    }
}
```

- [ ] **Step 3: Registrar aliases de middleware en el ServiceProvider**

En `src/InnertiaServiceProvider.php`, método `boot()`, bloque `// ── Middleware aliases`:

```php
// ── Middleware aliases ─────────────────────────────────────────────────────
$router = $this->app['router'];
$router->aliasMiddleware('app',            AppMiddleware::class);
$router->aliasMiddleware('role',           RoleMiddleware::class);
$router->aliasMiddleware('permission',     PermissionMiddleware::class);
$router->aliasMiddleware('tenant.resolve', \Innertia\Saas\Middleware\ResolveTenantFromHeader::class);
$router->aliasMiddleware('tenant.require', \Innertia\Saas\Middleware\RequireTenant::class);
```

- [ ] **Step 4: Tests**

```
./vendor/bin/pest
```

Expected: todos los tests pasan.

- [ ] **Step 5: Commit**

```bash
git add src/Saas/Middleware/ResolveTenantFromHeader.php \
        src/Saas/Middleware/RequireTenant.php \
        src/InnertiaServiceProvider.php
git commit -m "feat: add ResolveTenantFromHeader and RequireTenant middlewares"
```

---

## Task 6: Propagación de tenant en queue (UseCase)

**Files:**
- Modify: `src/Platform/Contracts/UseCase.php`

El objetivo: cuando un UseCase se construye durante un request con tenant activo, ese tenant debe restaurarse cuando el job se ejecuta en el worker.

- [ ] **Step 1: Actualizar UseCase**

```php
<?php
// src/Platform/Contracts/UseCase.php

namespace Innertia\Platform\Contracts;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Innertia\Facades\Innertia;

abstract class UseCase implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public string $queue   = 'use-cases';
    public int    $tries   = 3;
    public int    $timeout = 60;

    /**
     * Captura el key del tenant activo en el momento de construcción del UseCase.
     * Al ejecutarse en queue, se restaura el contexto antes de llamar execute().
     */
    protected ?string $__tenantKey = null;

    public function __construct()
    {
        // Capturar el tenant activo al momento de crear el UseCase.
        // Innertia::tenant() devuelve null en App mode — sin efecto.
        $this->__tenantKey = Innertia::tenant()?->key;
    }

    abstract public function execute(): mixed;

    /**
     * Llamado por el queue worker. Restaura el tenant antes de ejecutar.
     */
    public function handle(): void
    {
        if ($this->__tenantKey) {
            Innertia::activate($this->__tenantKey);
        }

        try {
            $this->execute();
        } finally {
            if ($this->__tenantKey) {
                Innertia::deactivate();
            }
        }
    }

    /**
     * Dispatch a una cola.
     *
     *   (new CreateOrder(...))->onQueue();            // → 'use-cases'
     *   (new CreateOrder(...))->onQueue('critical');  // → 'critical'
     */
    public function onQueue(?string $queue = null): void
    {
        $this->queue = $queue ?? 'use-cases';

        dispatch($this);
    }

    /**
     * Dispatch con delay.
     *
     *   (new CreateOrder(...))->delay(now()->addMinutes(5));
     *   (new CreateOrder(...))->delay(30); // seconds
     */
    public function delay(\DateTimeInterface|\DateInterval|int $delay): void
    {
        dispatch($this)->delay($delay);
    }
}
```

**Nota importante:** Los UseCases concretos llaman a `parent::__construct()` o no llaman a `__construct()` en absoluto si no tienen constructor propio. PHP no requiere que los hijos llamen a `parent::__construct()` explícitamente si tienen su propio constructor. Para capturar `$__tenantKey` en subclases que definen su propio constructor (que es el caso de TODOS los UseCases del paquete), deben llamar a `parent::__construct()`.

- [ ] **Step 2: Actualizar todos los UseCases que definen `__construct()` para que llamen `parent::__construct()`**

Buscar todos los UseCases con constructor propio:

```bash
grep -rn "public function __construct" /Users/guillermofarias/Sites/inertia/innertia-laravel/src --include="*.php" -l | xargs grep -l "extends UseCase"
```

Para cada uno, agregar `parent::__construct();` como primera línea del constructor. Ejemplo:

```php
// src/Auth/RBAC/UseCases/CreateRole.php
public function __construct(
    public readonly string  $name,
    public readonly ?string $description = null,
) {
    parent::__construct();
}
```

Aplicar el mismo cambio a todos los archivos encontrados en el paso anterior.

- [ ] **Step 3: Tests**

```
./vendor/bin/pest
```

Expected: todos los tests pasan.

- [ ] **Step 4: Commit**

```bash
git add src/Platform/Contracts/UseCase.php src/Auth/RBAC/UseCases/ src/Auth/UseCases/ src/Saas/UseCases/ src/Platform/UseCases/
git commit -m "feat: propagate tenant context to queued UseCases"
```

---

## Task 7: Reemplazar `function_exists('tenant') && tenant()` en todo el paquete

**Files:**
- Modify: `src/Platform/Traits/HasTenant.php`
- Modify: `src/Auth/RBAC/Models/Permission.php`
- Modify: `src/Auth/RBAC/Models/EntityPermission.php`
- Modify: `src/Auth/RBAC/Traits/HasRoles.php`
- Modify: `src/Auth/RBAC/Traits/HasApps.php`
- Modify: `src/Auth/RBAC/UseCases/CreateRole.php`
- Modify: `src/Auth/RBAC/Services/PermissionsService.php`
- Modify: `src/Platform/Traits/HasEntityPermissions.php`
- Modify: `src/Backoffice/Http/Controllers/RolesController.php`

El patrón a reemplazar es siempre el mismo. Hay dos variantes:

**Variante A** — obtener tenant_id como string:
```php
// Antes:
(function_exists('tenant') && tenant()) ? (string) tenant('id') : null

// Después:
\Innertia\Facades\Innertia::tenant()?->id
    ? (string) \Innertia\Facades\Innertia::tenant()->id
    : null
```

O más limpio usando el alias de clase si se agrega el use:
```php
use Innertia\Facades\Innertia;

// Reemplazar con:
Innertia::tenant()?->getKey() !== null ? (string) Innertia::tenant()->getKey() : null
```

**Variante B** — check booleano (¿hay tenant activo?):
```php
// Antes:
if (function_exists('tenant') && tenant()) {

// Después:
if (Innertia::tenant()) {
```

- [ ] **Step 1: Actualizar HasTenant**

```php
<?php
// src/Platform/Traits/HasTenant.php

namespace Innertia\Platform\Traits;

use Innertia\Facades\Innertia;

trait HasTenant
{
    public static function bootHasTenant(): void
    {
        if (config('innertia.mode') !== 'saas') {
            return; // no-op en modo single-tenant
        }

        static::creating(function ($model) {
            if (empty($model->tenant_id) && Innertia::tenant()) {
                $model->tenant_id = (string) Innertia::tenant()->getKey();
            }
        });

        static::addGlobalScope('tenant', function ($query) {
            if (Innertia::tenant()) {
                $query->where(
                    $query->getModel()->getTable() . '.tenant_id',
                    Innertia::tenant()->getKey()
                );
            }
        });
    }
}
```

- [ ] **Step 2: Actualizar Permission.php**

En `src/Auth/RBAC/Models/Permission.php`, agregar `use Innertia\Facades\Innertia;` y reemplazar:

```php
// Antes (línea ~40):
$tenantId = (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;

// Después:
$tenantId = Innertia::tenant() ? (string) Innertia::tenant()->getKey() : null;
```

- [ ] **Step 3: Actualizar EntityPermission.php**

En `src/Auth/RBAC/Models/EntityPermission.php`, agregar `use Innertia\Facades\Innertia;` y reemplazar:

```php
// Antes (línea ~65):
$tenantId ??= (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;

// Después:
$tenantId ??= Innertia::tenant() ? (string) Innertia::tenant()->getKey() : null;
```

- [ ] **Step 4: Actualizar HasRoles.php**

En `src/Auth/RBAC/Traits/HasRoles.php`, método `currentTenantId()`:

```php
private function currentTenantId(): ?string
{
    return \Innertia\Facades\Innertia::tenant()
        ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
        : null;
}
```

- [ ] **Step 5: Actualizar HasApps.php**

En `src/Auth/RBAC/Traits/HasApps.php`, mismo método:

```php
private function currentTenantId(): ?string
{
    return \Innertia\Facades\Innertia::tenant()
        ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
        : null;
}
```

- [ ] **Step 6: Actualizar CreateRole.php**

```php
// src/Auth/RBAC/UseCases/CreateRole.php

public function execute(): mixed
{
    $tenantId = \Innertia\Facades\Innertia::tenant()
        ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
        : null;

    return Role::createUnique($this->name, $this->description, $tenantId);
}
```

- [ ] **Step 7: Actualizar PermissionsService.php**

En `src/Auth/RBAC/Services/PermissionsService.php`, reemplazar las 2 ocurrencias:

```php
// sync() — línea ~134:
$tenantId = \Innertia\Facades\Innertia::tenant()
    ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
    : null;

// cacheKey() — línea ~200:
$tenantId = \Innertia\Facades\Innertia::tenant()
    ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
    : null;
```

- [ ] **Step 8: Actualizar HasEntityPermissions.php**

En `src/Platform/Traits/HasEntityPermissions.php`, línea ~84:

```php
$tenantId = \Innertia\Facades\Innertia::tenant()
    ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
    : null;
```

- [ ] **Step 9: Actualizar RolesController.php**

En `src/Backoffice/Http/Controllers/RolesController.php`, método `tenantId()`:

```php
private function tenantId(): ?string
{
    return \Innertia\Facades\Innertia::tenant()
        ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
        : null;
}
```

- [ ] **Step 10: Verificar que no queden referencias a stancl/tenant()**

```bash
grep -rn "function_exists('tenant')\|tenant()" /Users/guillermofarias/Sites/inertia/innertia-laravel/src --include="*.php"
```

Expected: sin resultados.

- [ ] **Step 11: Tests**

```
./vendor/bin/pest
```

Expected: todos los tests pasan.

- [ ] **Step 12: Commit**

```bash
git add src/Platform/Traits/HasTenant.php \
        src/Auth/RBAC/Models/ \
        src/Auth/RBAC/Traits/ \
        src/Auth/RBAC/UseCases/CreateRole.php \
        src/Auth/RBAC/Services/PermissionsService.php \
        src/Platform/Traits/HasEntityPermissions.php \
        src/Backoffice/Http/Controllers/RolesController.php
git commit -m "refactor: replace function_exists(tenant) with Innertia::tenant() everywhere"
```

---

## Task 8: Quitar stancl del template saas

**Files:**
- Modify: `innertia-setup/templates/saas/backend/composer.json`
- Modify: `innertia-setup/templates/saas/CLAUDE.md` (actualizar referencias a tenancy)
- Modify: `innertia-setup/templates/saas/backend/CLAUDE.md` (actualizar referencias)

- [ ] **Step 1: Actualizar template composer.json**

En `innertia-setup/templates/saas/backend/composer.json`:

1. Quitar `"stancl/tenancy": "^3.10"` de `"require"`.
2. Quitar `"stancl/tenancy"` de `"extra" > "laravel" > "dont-discover"`.

El bloque `require` queda:

```json
"require": {
    "php": "^8.4",
    "innertia-solutions/laravel-kit": "dev-main",
    "laravel/framework": "^13.0",
    "laravel/tinker": "^3.0",
    "tymon/jwt-auth": "^2.1",
    "league/flysystem-aws-s3-v3": "^3.0"
},
```

El bloque `"extra"` queda:

```json
"extra": {
    "laravel": {
        "dont-discover": [
            "innertia-solutions/laravel-kit"
        ]
    }
},
```

- [ ] **Step 2: Actualizar `innertia-setup/templates/saas/CLAUDE.md`**

Reemplazar todas las referencias a `stancl/tenancy` con una descripción del nuevo sistema:

```markdown
# {{PROJECT_NAME}} — SaaS (Laravel + Nuxt + Multitenancy)

## Stack
- Laravel 13, PHP 8.4 (backend/) — REST API con multitenancy via `Innertia::tenant()`
- Nuxt 3 (frontend/) — SPA/SSR
- PostgreSQL 16, Redis 7
- Docker Compose
- `innertia-solutions/laravel-kit` — DataTable, ActivityLogger, EntityHistory, HasNanoId, Auditable, TenantManager
- `tymon/jwt-auth` — autenticación JWT

## Commands
- `docker compose up` — inicia todos los servicios
- `docker compose exec api php artisan migrate`
- `docker compose exec api php artisan tinker`
- `docker compose exec api php artisan test`
- `docker compose exec api php artisan tenant:list`
- `docker compose exec api php artisan tenant:create acme "Acme Corp"`
- `docker compose exec api php artisan tenant:show acme`

## Ports
- API: http://localhost:{{APP_PORT}}
- Frontend: http://localhost:{{FRONTEND_PORT}}
- DB: localhost:{{DB_PORT}}
- Redis: localhost:{{REDIS_PORT}}

## Architecture

- `backend/` — Laravel API con multitenancy single-DB. Todos los modelos tenant usan `tenant_id`.
- `frontend/` — Nuxt 3. Consume la API vía proxy `/api/**` → `http://api:80/**`.

### Multitenancy

Identificación de tenant vía header `X-Tenant: {key}`. El middleware `tenant.resolve` lo activa.

```
// Obtener tenant activo:
Innertia::tenant()           // Tenant|null (runtime)

// Activar un tenant manualmente (CLI/tinker):
Innertia::activate('acme')  // Tenant (busca + setea contexto)

// Desactivar:
Innertia::deactivate()
```

Rutas públicas (sin auth): `routes/api.public.php`
Rutas privadas (con auth): `routes/api.private.php`

Los modelos tenant usan `HasTenant` — global scope automático por `tenant_id`.
Los UseCases propagan el tenant activo al despacharse a queue.

### DDD personalizado (backend/)

```
backend/app/
├── Domains/          # Entidades de negocio.
│   └── {Domain}/
│       ├── Models/
│       ├── UseCases/
│       ├── Services/
│       ├── Events/
│       ├── Listeners/
│       ├── Gates/
│       ├── Enums/
│       ├── Mails/
│       └── Observers/
│
├── Apps/             # Capa HTTP.
│   └── {AppName}/
│       └── {Domain}/Controllers/
│
└── Platform/         # Infraestructura compartida.
```

### Reglas

- Controllers solo validan y delegan a UseCases.
- UseCases extienden `\Innertia\Platform\Contracts\UseCase`, parámetros en constructor.
- Models con `HasTenant` trait aplican global scope de tenant automáticamente.
```

- [ ] **Step 3: Tests**

```
./vendor/bin/pest
```

Expected: todos los tests pasan.

- [ ] **Step 4: Commit**

```bash
git add innertia-setup/templates/saas/backend/composer.json \
        innertia-setup/templates/saas/CLAUDE.md
git commit -m "chore: remove stancl/tenancy from saas template"
```

---

## Task 9: Route split — api.public.php + api.private.php

**Files:**
- Create: `stubs/app/api.public.php`
- Create: `stubs/app/api.private.php`
- Modify: `stubs/app/api.php` (solo require ambos)
- Create: `stubs/saas/api.public.php`
- Create: `stubs/saas/api.private.php`
- Modify: `stubs/saas/api.php` (solo require ambos)
- Modify: `src/InnertiaServiceProvider.php` (publicar 3 archivos por modo)

- [ ] **Step 1: Crear stubs/app/api.public.php**

```php
<?php
// stubs/app/api.public.php — rutas sin autenticación (App mode)

use Illuminate\Support\Facades\Route;
use App\Apps\Backoffice\Auth\AuthController;
use App\Apps\Backoffice\Auth\PasswordController;
use Innertia\Auth\Http\Controllers\EmailVerificationController;
use Innertia\Auth\Http\Controllers\OtpController;
use Innertia\Auth\Http\Controllers\SocialAuthController;
use Innertia\Auth\Http\Controllers\TwoFactorController;

Route::prefix('auth')->group(function () {

    Route::post('login',             [AuthController::class, 'login']);
    Route::post('otp/send',          [OtpController::class, 'send']);
    Route::post('otp/verify',        [OtpController::class, 'verify']);
    Route::post('2fa/verify',        [TwoFactorController::class, 'verify']);
    Route::post('email/verify/send', [EmailVerificationController::class, 'send']);
    Route::get ('email/verify',      [EmailVerificationController::class, 'verify'])->name('auth.email.verify');
    Route::post('password/change',   [PasswordController::class, 'change']);
    Route::post('password/set',      [PasswordController::class, 'set']);

    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->where('provider', 'google|microsoft|github');
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
        ->where('provider', 'google|microsoft|github');
});
```

- [ ] **Step 2: Crear stubs/app/api.private.php**

```php
<?php
// stubs/app/api.private.php — rutas con autenticación requerida (App mode)

use Illuminate\Support\Facades\Route;
use App\Apps\Backoffice\Auth\AuthController;
use App\Apps\Backoffice\Users\UsersController;
use App\Apps\Backoffice\Roles\RolesController;
use App\Apps\Backoffice\Permissions\PermissionsController;
use Innertia\Auth\Http\Controllers\SocialSettingsController;
use Innertia\Auth\Http\Controllers\TwoFactorController;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Notifications\Http\NotificationsController;
use Innertia\Platform\Http\Controllers\SubscriptionController;

Route::middleware(Authenticate::class)->group(function () {

    // ── Auth ──────────────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::get ('me',             [AuthController::class, 'me']);
        Route::get ('me/permissions', [AuthController::class, 'mePermissions']);
        Route::post('refresh',        [AuthController::class, 'refresh']);
        Route::post('logout',         [AuthController::class, 'logout']);
        Route::post('2fa/enable',     [TwoFactorController::class, 'enable']);
        Route::post('2fa/disable',    [TwoFactorController::class, 'disable']);
    });

    // ── Suscripciones ─────────────────────────────────────────────────────────
    Route::prefix('subscriptions')->group(function () {
        Route::get   ('/',     [SubscriptionController::class, 'index']);
        Route::post  ('/',     [SubscriptionController::class, 'store']);
        Route::patch ('{id}',  [SubscriptionController::class, 'update']);
        Route::delete('{id}',  [SubscriptionController::class, 'destroy']);
    });

    // ── Notificaciones ────────────────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get   ('/',          [NotificationsController::class, 'index']);
        Route::patch ('/read-all',  [NotificationsController::class, 'markAllRead']);
        Route::patch ('/{id}/read', [NotificationsController::class, 'markRead']);
        Route::delete('/',          [NotificationsController::class, 'destroyRead']);
        Route::delete('/{id}',      [NotificationsController::class, 'destroy']);
    });

    // ── Admin: configuración de proveedores sociales ──────────────────────────
    Route::prefix('admin/auth')->group(function () {
        Route::get('settings',            [SocialSettingsController::class, 'index']);
        Route::get('{provider}/settings', [SocialSettingsController::class, 'show'])
            ->where('provider', 'google|microsoft|github');
        Route::put('{provider}/settings', [SocialSettingsController::class, 'update'])
            ->where('provider', 'google|microsoft|github');
    });

    // ── Backoffice ────────────────────────────────────────────────────────────
    Route::prefix('backoffice')->group(function () {

        // Usuarios
        Route::get   ('users',                     [UsersController::class, 'index']);
        Route::post  ('users',                     [UsersController::class, 'store']);
        Route::get   ('users/{id}',                [UsersController::class, 'show']);
        Route::put   ('users/{id}',                [UsersController::class, 'update']);
        Route::delete('users/{id}',                [UsersController::class, 'destroy']);
        Route::get   ('users/{id}/roles',          [UsersController::class, 'roles']);
        Route::post  ('users/{id}/roles',          [UsersController::class, 'assignRole']);
        Route::delete('users/{id}/roles/{role}',   [UsersController::class, 'removeRole']);
        Route::get   ('users/{id}/apps',           [UsersController::class, 'apps']);
        Route::post  ('users/{id}/apps',           [UsersController::class, 'grantApp']);
        Route::post  ('users/{id}/apps/sync',      [UsersController::class, 'syncApps']);
        Route::delete('users/{id}/apps/{app}',     [UsersController::class, 'revokeApp']);
        Route::get   ('users/{id}/sessions',       [UsersController::class, 'sessions']);
        Route::delete('users/{id}/sessions/{sid}', [UsersController::class, 'revokeSession']);
        Route::delete('users/{id}/sessions',       [UsersController::class, 'revokeAllSessions']);
        Route::post  ('users/{id}/reactivate',     [UsersController::class, 'reactivate']);
        Route::post  ('users/{id}/reset-password', [UsersController::class, 'resetPassword']);
        Route::get   ('users/{id}/activity',       [UsersController::class, 'activity']);

        // Roles
        Route::get   ('roles',                    [RolesController::class, 'index']);
        Route::post  ('roles',                    [RolesController::class, 'store']);
        Route::get   ('roles/{id}',               [RolesController::class, 'show']);
        Route::put   ('roles/{id}',               [RolesController::class, 'update']);
        Route::delete('roles/{id}',               [RolesController::class, 'destroy']);
        Route::post  ('roles/{id}/permissions',   [RolesController::class, 'syncPermissions']);

        // Permisos
        Route::get('permissions', [PermissionsController::class, 'index']);
    });

    // ── Rutas de la aplicación ────────────────────────────────────────────────

});
```

- [ ] **Step 3: Actualizar stubs/app/api.php**

```php
<?php
// stubs/app/api.php — punto de entrada de rutas API (App mode)
// Este archivo lo publica innertia-kit via vendor:publish --tag=innertia-routes.
// Puedes moverlo todo a un solo archivo o mantener la separación public/private.

require __DIR__ . '/api.public.php';
require __DIR__ . '/api.private.php';
```

- [ ] **Step 4: Crear stubs/saas/api.public.php**

```php
<?php
// stubs/saas/api.public.php — rutas sin autenticación (SaaS mode)
// El middleware tenant.resolve se aplica globalmente en bootstrap/app.php.

use Illuminate\Support\Facades\Route;
use App\Apps\Backoffice\Auth\AuthController;
use App\Apps\Backoffice\Auth\PasswordController;
use Innertia\Auth\Http\Controllers\EmailVerificationController;
use Innertia\Auth\Http\Controllers\OtpController;
use Innertia\Auth\Http\Controllers\SocialAuthController;
use Innertia\Auth\Http\Controllers\TwoFactorController;
use Innertia\Saas\Middleware\ResolveTenantFromHeader;

Route::middleware(ResolveTenantFromHeader::class)->prefix('auth')->group(function () {

    Route::post('login',             [AuthController::class, 'login']);
    Route::post('otp/send',          [OtpController::class, 'send']);
    Route::post('otp/verify',        [OtpController::class, 'verify']);
    Route::post('2fa/verify',        [TwoFactorController::class, 'verify']);
    Route::post('email/verify/send', [EmailVerificationController::class, 'send']);
    Route::get ('email/verify',      [EmailVerificationController::class, 'verify'])->name('auth.email.verify');
    Route::post('password/change',   [PasswordController::class, 'change']);
    Route::post('password/set',      [PasswordController::class, 'set']);

    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->where('provider', 'google|microsoft|github');
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback'])
        ->where('provider', 'google|microsoft|github');
});
```

- [ ] **Step 5: Crear stubs/saas/api.private.php**

```php
<?php
// stubs/saas/api.private.php — rutas con autenticación requerida (SaaS mode)

use Illuminate\Support\Facades\Route;
use App\Apps\Backoffice\Auth\AuthController;
use App\Apps\Backoffice\Users\UsersController;
use App\Apps\Backoffice\Roles\RolesController;
use App\Apps\Backoffice\Permissions\PermissionsController;
use Innertia\Auth\Http\Controllers\SocialSettingsController;
use Innertia\Auth\Http\Controllers\TwoFactorController;
use Innertia\Auth\Middleware\Authenticate;
use Innertia\Notifications\Http\NotificationsController;
use Innertia\Platform\Http\Controllers\SubscriptionController;
use Innertia\Saas\Middleware\RequireTenant;
use Innertia\Saas\Middleware\ResolveTenantFromHeader;

Route::middleware([ResolveTenantFromHeader::class, Authenticate::class, RequireTenant::class])->group(function () {

    // ── Auth ──────────────────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::get ('me',             [AuthController::class, 'me']);
        Route::get ('me/permissions', [AuthController::class, 'mePermissions']);
        Route::post('refresh',        [AuthController::class, 'refresh']);
        Route::post('logout',         [AuthController::class, 'logout']);
        Route::post('2fa/enable',     [TwoFactorController::class, 'enable']);
        Route::post('2fa/disable',    [TwoFactorController::class, 'disable']);
    });

    // ── Suscripciones ─────────────────────────────────────────────────────────
    Route::prefix('subscriptions')->group(function () {
        Route::get   ('/',     [SubscriptionController::class, 'index']);
        Route::post  ('/',     [SubscriptionController::class, 'store']);
        Route::patch ('{id}',  [SubscriptionController::class, 'update']);
        Route::delete('{id}',  [SubscriptionController::class, 'destroy']);
    });

    // ── Notificaciones ────────────────────────────────────────────────────────
    Route::prefix('notifications')->group(function () {
        Route::get   ('/',          [NotificationsController::class, 'index']);
        Route::patch ('/read-all',  [NotificationsController::class, 'markAllRead']);
        Route::patch ('/{id}/read', [NotificationsController::class, 'markRead']);
        Route::delete('/',          [NotificationsController::class, 'destroyRead']);
        Route::delete('/{id}',      [NotificationsController::class, 'destroy']);
    });

    // ── Admin: configuración de proveedores sociales ──────────────────────────
    Route::prefix('admin/auth')->group(function () {
        Route::get('settings',            [SocialSettingsController::class, 'index']);
        Route::get('{provider}/settings', [SocialSettingsController::class, 'show'])
            ->where('provider', 'google|microsoft|github');
        Route::put('{provider}/settings', [SocialSettingsController::class, 'update'])
            ->where('provider', 'google|microsoft|github');
    });

    // ── Backoffice ────────────────────────────────────────────────────────────
    Route::prefix('backoffice')->group(function () {

        // Usuarios
        Route::get   ('users',                     [UsersController::class, 'index']);
        Route::post  ('users',                     [UsersController::class, 'store']);
        Route::get   ('users/{id}',                [UsersController::class, 'show']);
        Route::put   ('users/{id}',                [UsersController::class, 'update']);
        Route::delete('users/{id}',                [UsersController::class, 'destroy']);
        Route::get   ('users/{id}/roles',          [UsersController::class, 'roles']);
        Route::post  ('users/{id}/roles',          [UsersController::class, 'assignRole']);
        Route::delete('users/{id}/roles/{role}',   [UsersController::class, 'removeRole']);
        Route::get   ('users/{id}/apps',           [UsersController::class, 'apps']);
        Route::post  ('users/{id}/apps',           [UsersController::class, 'grantApp']);
        Route::post  ('users/{id}/apps/sync',      [UsersController::class, 'syncApps']);
        Route::delete('users/{id}/apps/{app}',     [UsersController::class, 'revokeApp']);
        Route::get   ('users/{id}/sessions',       [UsersController::class, 'sessions']);
        Route::delete('users/{id}/sessions/{sid}', [UsersController::class, 'revokeSession']);
        Route::delete('users/{id}/sessions',       [UsersController::class, 'revokeAllSessions']);
        Route::post  ('users/{id}/reactivate',     [UsersController::class, 'reactivate']);
        Route::post  ('users/{id}/reset-password', [UsersController::class, 'resetPassword']);
        Route::get   ('users/{id}/activity',       [UsersController::class, 'activity']);

        // Roles
        Route::get   ('roles',                    [RolesController::class, 'index']);
        Route::post  ('roles',                    [RolesController::class, 'store']);
        Route::get   ('roles/{id}',               [RolesController::class, 'show']);
        Route::put   ('roles/{id}',               [RolesController::class, 'update']);
        Route::delete('roles/{id}',               [RolesController::class, 'destroy']);
        Route::post  ('roles/{id}/permissions',   [RolesController::class, 'syncPermissions']);

        // Permisos
        Route::get('permissions', [PermissionsController::class, 'index']);
    });

    // ── Rutas de la aplicación ────────────────────────────────────────────────

});
```

- [ ] **Step 6: Actualizar stubs/saas/api.php**

```php
<?php
// stubs/saas/api.php — punto de entrada de rutas API (SaaS mode)
// Este archivo lo publica innertia-kit via vendor:publish --tag=innertia-routes.

require __DIR__ . '/api.public.php';
require __DIR__ . '/api.private.php';
```

- [ ] **Step 7: Actualizar publishable en InnertiaServiceProvider**

En `src/InnertiaServiceProvider.php`, método `boot()`, actualizar el bloque `innertia-routes`:

```php
// Stubs de rutas: api.php + api.public.php + api.private.php
$stub = $this->isSaas() ? 'saas' : 'app';
$this->publishes([
    __DIR__ . "/../stubs/{$stub}/api.php"         => base_path('routes/api.php'),
    __DIR__ . "/../stubs/{$stub}/api.public.php"  => base_path('routes/api.public.php'),
    __DIR__ . "/../stubs/{$stub}/api.private.php" => base_path('routes/api.private.php'),
], 'innertia-routes');
```

- [ ] **Step 8: Actualizar post-install.ts** para que publique en un solo paso (sin cambios — `vendor:publish --tag=innertia-routes` ya publica los 3 archivos juntos)

Verificar que `innertia-setup/scripts/post-install.ts` tenga este bloque (ya existe, sin cambio):

```typescript
execSync(
  'php artisan vendor:publish --tag=innertia-routes --no-interaction',
  { cwd: composerDir, stdio: 'pipe' }
)
```

- [ ] **Step 9: Tests**

```
./vendor/bin/pest
```

Expected: todos los tests pasan.

- [ ] **Step 10: Commit**

```bash
git add stubs/ src/InnertiaServiceProvider.php
git commit -m "feat: split routes into api.public.php + api.private.php for app and saas modes"
```

---

## Verificación final

- [ ] **Verificar que no hay referencias stancl en src/**

```bash
grep -rn "stancl\|Stancl\|function_exists('tenant')\|tenancy()" \
    /Users/guillermofarias/Sites/inertia/innertia-laravel/src --include="*.php"
```

Expected: sin resultados.

- [ ] **Verificar que no hay referencias stancl en templates**

```bash
grep -rn "stancl" \
    /Users/guillermofarias/Sites/inertia/innertia-setup/templates --include="*.json"
```

Expected: sin resultados.

- [ ] **Run full test suite**

```
./vendor/bin/pest --coverage
```

Expected: todos los tests pasan.

- [ ] **Commit final (si hay algo sin commitear)**

```bash
git status
# Si hay archivos sin commitear:
git add -A
git commit -m "chore: complete stancl/tenancy removal and Innertia::tenant() migration"
```
