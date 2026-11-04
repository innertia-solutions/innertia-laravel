---
name: innertia-organizations
description: Use when working with Innertia Organizations — sub-tenant scoping. Trigger for mentions of organizations, multi-org, X-Organization header, HasOrganization trait, OrganizationContext, or when adding a column scoped per organization to a model.
---

# Innertia Organizations — sub-tenant scoping

Organizations son agrupaciones **dentro** de un tenant. Cada tenant puede tener N orgs. Modelos con el trait `HasOrganization` quedan filtrados automáticamente por la org activa via global scope.

## Activación

```bash
# .env
INNERTIA_ORGANIZATIONS_ENABLED=true

# config/innertia.php (asegurar que existe el bloque)
'organizations' => [
    'enabled' => env('INNERTIA_ORGANIZATIONS_ENABLED', false),
    'tables'  => ['documents', 'projects', 'findings'],  // ← tablas a scopar
    'column'  => 'organization_id',
    'with_index' => true,
    'model'   => \Innertia\Platform\Organizations\Models\Organization::class,
],
```

```bash
# Genera la migration consolidada
php artisan innertia:organization:install

# Si después agregás más tablas a config.tables, regenerás con --force
php artisan innertia:organization:install --force

php artisan migrate
```

El install agrega `organization_id` (nullable bigint) a:
- Las tablas declaradas en `config.tables`
- Las RBAC: `roles`, `model_roles`, `model_permissions`, `user_contexts`

NULL = "global al tenant" (visible desde cualquier org scope).

Además rehace las unicidades: `model_roles` pierde la PK de 3 columnas → `model_roles_scoped_unique` (con `organization_id`) y `user_contexts` → `user_contexts_scoped_unique`, para que el mismo rol/contexto viva en varias orgs.

Modos: `app`, `saas` y `open` (en `api` siempre inactivo). En `open`, sin tenant activo el header `X-Organization` se ignora.

## Acceso a una org (regla única)

- **Entra a la org X** quien tenga fila en `user_contexts` (tenant activo) con `organization_id = X` o `NULL` (NULL = todas las orgs del tenant).
- **Qué puede hacer**: roles en `model_roles` con `organization_id = X` o `NULL` (global).
- `ResolveOrganizationFromHeader` responde 403 `organization_inactive` (org desactivada) u 403 `organization_forbidden` (sin membresía); `is_platform_admin` se salta la membresía. Está montado también en `/auth/*` autenticado.
- `/auth/me` devuelve `current_organization` y `permissions` filtrados a roles globales + de la org activa.

```php
$user->grantContext('backoffice', $orgId);  // miembro de una sede (null = todas)
$user->assignRole('operator', $orgId);      // mismo rol en varias orgs (no pisa)
$user->removeRole('operator', $orgId);      // solo esa org; sin $orgId quita todas
$user->accessibleOrganizationIds();         // int[] orgs activas accesibles (viene en HasContexts)
$user->canAccessOrganization($orgId);       // bool
```

## Headers HTTP

```
X-Tenant: acme              # tenant activo
X-Organization: north-cl    # org activa (slug del campo 'key')
X-Consolidated: true        # opcional: scope = $user->accessibleOrganizationIds()
```

## Contexto en runtime

```php
Innertia::organization()->current()   // ?int — UNA org para writes
Innertia::organization()->scope()     // array<int> — orgs ids para reads
Innertia::organization()->withOrganization(5, fn () => /* ... */);
```

- `current()` se usa al crear (HasOrganization auto-inyecta el `organization_id`)
- `scope()` se usa al leer (global scope: `WHERE organization_id IN scope()`)
- Default: `scope = [current]`
- Vista consolidada: `scope` contiene N orgs (todas las que el user puede ver)

## Trait HasOrganization en modelos

```php
use Innertia\Platform\Traits\HasOrganization;
use Innertia\Platform\Traits\HasTenant;

class Document extends Model {
    use HasTenant, HasOrganization;

    protected $fillable = ['tenant_id', 'organization_id', /* ... */];
}

// Crear: organization_id se llena automáticamente desde el contexto
Document::create([...]);

// Leer: WHERE organization_id IN (scope())
Document::all();
```

Cuando el feature está OFF o el contexto está vacío (CLI/jobs), el trait es no-op.

## Middlewares disponibles

```php
'organization.resolve'   // lee X-Organization, valida org activa + membresía y popula OrganizationContext
'organization.require'   // 401 si no hay org activa (estricto)
```

En rutas privadas:

```php
Route::middleware([
    'tenant.resolve', 'auth:api', 'tenant.require',
    'organization.resolve',                    // siempre — populate del header
    'organization.require',                    // opcional — forzar header
])->group(...)
```

## CRUD HTTP opt-in

El paquete trae controller y helper para montar:

```php
// routes/api.private.php
Route::middleware(['auth:api', 'tenant.require'])->group(function () {
    \Innertia\Platform\Organizations\Routes::register();
});
```

Endpoints generados:
- `GET    /organizations`        — DataTable list
- `POST   /organizations`        — crear (validation: name, key slug regex, active)
- `GET    /organizations/{id}`   — detail
- `PUT    /organizations/{id}`   — update
- `DELETE /organizations/{id}`   — soft delete

## Cómo crear una org desde código

```php
use Innertia\Platform\Organizations\UseCases\CreateOrganization;

$org = (new CreateOrganization(
    tenantId: Innertia::tenant()->getKey(),
    name:     'North Region',
    key:      'north-region',     // slug, único por tenant
    active:   true,
))->execute();
```

O via artisan:

```bash
php artisan innertia:organization:create acme north-region "North Region"
php artisan innertia:organization:list --tenant=acme
```

## Cómo extender el modelo Organization (agregar columnas)

5 pasos para agregar p.ej. `owner_id`:

```php
// 1. Migration de la app
Schema::table('organizations', fn ($t) => $t->uuid('owner_id')->nullable());

// 2. Modelo extendido
class Organization extends \Innertia\Platform\Organizations\Models\Organization {
    protected $fillable = [...parent::$fillable, 'owner_id'];
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
}

// 3. config/innertia.php
'organizations' => [
    // ...
    'model' => \App\Domains\Organizations\Models\Organization::class,
],

// 4. Controller extendido — hooks
class OrganizationsController extends \Innertia\Platform\Organizations\Http\Controllers\OrganizationsController {
    protected function extraStoreRules(): array {
        return ['owner_id' => 'required|uuid|exists:users,id'];
    }
    protected function extraUpdateRules(): array {
        return ['owner_id' => 'sometimes|uuid|exists:users,id'];
    }
    protected function extraFields(Request $r, $org = null): array {
        return array_filter(['owner_id' => $r->input('owner_id')], fn ($v) => $v !== null);
    }
    protected function indexColumns(): array {
        return [...parent::indexColumns(), 'owner_id'];
    }
}

// 5. Mount con el controller de la app
\Innertia\Platform\Organizations\Routes::register('organizations', \App\Http\OrganizationsController::class);
```

Ver el skill `innertia-extending` para los niveles más profundos (override de UseCases, side-effects).

## Validación de coherencia

```bash
php artisan innertia:organization:check
```

Recorre todos los modelos con `HasOrganization`, verifica que la tabla esté declarada en `config.tables` y que la columna exista. Útil en CI.

## Bug conocido — composer install desactualizado

Si `php artisan list innertia` no muestra `innertia:organization:*`, es porque el paquete vendored está desactualizado. Solución:

```bash
docker exec ... composer update innertia-solutions/laravel-innertia --with-dependencies
```

## Eventos emitidos

Organizations emite 3 eventos típados:

| Evento | Cuándo dispara | Payload |
|---|---|---|
| `OrganizationEvent::Created` | `CreateOrganization::execute()` | organization_id, key, name, active |
| `OrganizationEvent::Updated` | `UpdateOrganization::execute()` | organization_id, changes (old/new — incluye active flag para detectar activate/deactivate) |
| `OrganizationEvent::Deleted` | `DeleteOrganization::execute()` | organization_id, key, name |

Note: activate/deactivate se detecta vía changes payload de `Updated` — no hay eventos separados.

## Skills relacionados

- `innertia-teams` — los Teams también pueden ser org-scoped (cada team pertenece a una org)
- `innertia-extending` — patrón template method para customización
- `innertia-config` — bloque completo de `config('innertia.organizations')`
