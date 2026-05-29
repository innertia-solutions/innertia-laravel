# innertia-laravel — Contexto para Claude

Framework Laravel interno de Innertia Solutions. Proporciona la capa de plataforma para
backends single-app y SaaS. **No depende de stancl/tenancy** — manager de tenants propio.

## Composición

```
src/
├── Auth/                 # JWT, OTP, 2FA, OAuth, RBAC (roles, permissions, contexts)
├── Saas/                 # Multitenancy propio (Innertia::tenant(), HasTenant trait)
├── Platform/             # Core: UseCase, DomainEvent, DomainGate, Organizations, Teams
│   ├── Organizations/
│   └── Teams/
├── Settings/             # Config per-tenant + /preferences endpoints
├── Notifications/        # Web notification center
├── Webhooks/
├── Mail/                 # InnertiaMailable con branding por-tenant
├── Workflow/             # Engine de máquinas de estado
├── Files/                # Storage + HasSingleFile trait
├── DataTable/            # Server-side tables (paginación, sort, search, export)
└── Telemetry/            # Métricas + logs internos
```

## Modos de operación

`config('innertia.mode')`:

| Modo | Multitenancy | Auth | Description |
|------|-------------|------|-------------|
| `app` | ❌ | ✅ JWT | Single-tenant. App interna. |
| `saas` | ✅ | ✅ JWT | Multi-tenant via `X-Tenant` header. |
| `api` | ❌ | 🔑 API key | API mode. **Sin usuarios, sin JWT.** Autentica organizaciones vía `verify.api.key` (header `X-Api-Key`). El admin de orgs/keys va bajo `/olimpo` protegido por `olimpo.auth` (X-Olimpo-Key). No publica `config/auth.php` — `configureAuth()` no cablea auth en este modo. |

## Features core (siempre activos)

| Feature | Descripción |
|---|---|
| **Files** | Upload, storage, papelera (soft delete / restore / hard delete), HasTags en archivos, 6 eventos tipados (FileEvent), endpoints CRUD via `\Innertia\Files\Routes::register()`. **No tiene feature flag** — activo desde que el paquete está instalado. `File::delete()` es soft (storage preservado); usar `forceDelete()` para borrado físico. Inline view en `/files/{id}/view` (named route `innertia.files.view`). Sharing: grantAccessTo($user)/revokeAccessFrom($user) en files y directories; acceso heredado desde ancestros; endpoints /files/{id}/grants, /directories/{id}/grants, /files/shared-with-me via Routes::register(). |

## Features opt-in (config flags)

| Feature | Flag | Install | Schema |
|---|---|---|---|
| Organizations | `INNERTIA_ORGANIZATIONS_ENABLED=true` | `php artisan innertia:organization:install [--force]` | Crea `organizations`, agrega `organization_id` a `roles`, `model_roles`, `model_permissions`, `user_contexts`, y tablas declaradas |
| Teams | `INNERTIA_TEAMS_ENABLED=true` | `php artisan innertia:teams:install [--force]` | Crea `teams`, `team_members` |
| Tags | `INNERTIA_TAGS_ENABLED=true` | `php artisan innertia:tags:install [--force]` | Crea `tags`, `taggables` (polimórfica). Tenant-scoped. Trait `HasTags` para cualquier modelo. Cuando activo, `File` tiene `HasTags` disponible. |
| Directories | `INNERTIA_DIRECTORIES_ENABLED=true` | `php artisan innertia:directories:install [--force]` | Crea `directories` (jerarquía con materialized path, trash con trash_group_id). Agrega `directory_id` a `files` (idempotente). Trait HasTags aplicable. Eventos vía DirectoryEvent enum. |

Cada feature tiene un gate único:
- `\Innertia\Platform\Organizations\OrganizationsFeature::isActive()`
- `\Innertia\Platform\Teams\TeamsFeature::isActive()`
- `\Innertia\Tags\TagsFeature::isActive()`
- `\Innertia\Files\Directories\DirectoriesFeature::isActive()`

`--force` regenera la migration con timestamp fresco. La migration usa `Schema::hasColumn()` para saltar tablas ya scopeadas — segura de re-aplicar cuando agregás tablas a `config('innertia.organizations.tables')`.

### HTTP layer (opt-in)

Ambos features traen controller default + helper para montar las rutas CRUD:

```php
// routes/api.private.php
Route::middleware(['auth:api', 'tenant.require'])->group(function () {
    \Innertia\Platform\Organizations\Routes::register();  // /organizations CRUD
    \Innertia\Platform\Teams\Routes::register();          // /teams CRUD + /teams/{id}/members
});
```

Argumentos: `Routes::register(prefix, controller)`.

### Patrón de extensión (template method)

Cada UseCase (`Create/Update {Organization,Team}`) acepta `$extra = []` y expone `attributes()` protected. Cada Controller expone hooks `extraStoreRules()`, `extraUpdateRules()`, `extraFields(Request, ?Model)`.

Para agregar `owner_id` a Organizations:

1. Migration de la app: `alter table organizations add owner_id`
2. Modelo extendido: `class Organization extends \Innertia\...\Organization { protected $fillable = [...parent::$fillable, 'owner_id']; }`
3. `config('innertia.organizations.model') = App\Models\Organization::class`
4. Controller extendido:
   ```php
   class OrganizationsController extends \Innertia\...\OrganizationsController {
       protected function extraStoreRules(): array { return ['owner_id' => 'required|uuid|exists:users,id']; }
       protected function extraFields(Request $r, $org = null): array { return ['owner_id' => $r->input('owner_id')]; }
   }
   ```
5. `Routes::register('organizations', App\Http\OrganizationsController::class)`

Niveles:
- **Columnas + validación**: hooks del controller (lo de arriba)
- **Mapping de atributos / transform**: extender UseCase, override `attributes()`
- **Side-effects post-create**: extender UseCase, override `execute()`
- **Reemplazo total del controller**: forkear y mountar con `Routes::register(prefix, App\Controller::class)`

### Artisan commands

```bash
innertia:organization:create {tenant} {key} {name} [--inactive]
innertia:organization:list [--tenant=]
innertia:organization:check          # coherencia trait ↔ config ↔ schema
innertia:team:create {tenant} {name} [--description=] [--parent=] [--org=]
innertia:team:list [--tenant=]
innertia:skills:install [--force]    # copia skills del paquete a .claude/skills/innertia/
```

### Claude Code skills

El paquete trae skills versionados en `src/Skills/*.md`. Cada proyecto consumidor los instala con `php artisan innertia:skills:install`. Skills disponibles:

- `innertia-framework` — overview, modes, estructura DDD
- `innertia-organizations` — multi-org scoping + cómo crear/extender
- `innertia-teams` — RBAC por grupo + cómo crear/extender
- `innertia-tags` — sistema de tags polimórfico, trait HasTags, endpoints CRUD, extensión
- `innertia-permissions` — las 8 fuentes de permisos, DomainGates, EntityPermission, HasContexts
- `innertia-events` — Event Bus tipado, DomainEventKey enums, Triggers, EventBusFake, catálogo introspectable
- `innertia-webhooks` — outbound webhooks con HMAC signing
- `innertia-mail` — InnertiaMailable, branding por tenant, NotificationMail fluent builder
- `innertia-config` — referencia de config/innertia.php
- `innertia-storage` — File model con soft delete, HasTags, HasEntityPermissions, eventos, papelera, integración con Directorios
- `innertia-extending` — patrón template method para extender el paquete
- `innertia-directories` — árbol de carpetas con materialized path, trash agrupado, eventos tipados (DirectoryEvent)

Mantenerlos sincronizados con la realidad del código es responsabilidad del paquete — cualquier feature/refactor que cambia uso público debería actualizar el skill correspondiente.

### Auto-traits en User base

`\Innertia\Auth\Models\User` aplica `HasTeams` automáticamente. Si TeamsFeature está disabled el trait es no-op (cero overhead). `HasOrganization` NO se aplica al User — los users son tenant-level y el contexto multi-org se mediza vía `HasContexts` (la tabla `user_contexts` tiene `organization_id`).

## Sistema de permisos — combinaciones posibles

RBAC con multiples fuentes de permisos. Cada usuario puede tener permisos asignados por:

| # | Fuente | Cómo | Scope tenant | Scope org | Scope team | Ejemplo |
|---|---|---|---|---|---|---|
| 1 | **Direct grant** | `$user->givePermission('users.view')` | tenant actual | si activo, org actual | n/a | Pepe puede ver users en este tenant |
| 2 | **Direct grant por org** | `$user->givePermission('users.view', $orgId)` | tenant | org específica | n/a | Pepe puede ver users SOLO en org A |
| 3 | **Via rol del user** | `$user->assignRole('admin')` | tenant | org actual (si activo) | n/a | Pepe es admin → hereda permisos del rol |
| 4 | **Via rol del user en org** | `$user->assignRole('admin', $orgId)` | tenant | org específica | n/a | Pepe es admin SOLO en org A |
| 5 | **Via team membership** | `$user->teams()->attach($team)` + team con rol | tenant | org del team | team | Pepe en team Marketing → hereda roles del team |
| 6 | **Via team con rol por org** | `model_roles(team_id, role_id, org=X)` | tenant | org X | team | Team Marketing tiene rol editor en org A |
| 7 | **Entity grant directo** | `entity_permissions(entity, user_id, action)` | tenant | org del entity | n/a | Pepe tiene access al doc #42 específicamente |
| 8 | **Entity grant via team** | `entity_permissions(entity, team_id, action)` | tenant | org del entity | team | Team Operations tiene edit en folder X |

### Resolución

`$user->resolvedPermissions()` o `/auth/me` consolida:
```
direct grants ∪ permisos de roles del user ∪ permisos de roles de teams del user
filtered by OrganizationContext::scope() cuando organizations activo
```

`entity_permissions` se evalúan **por recurso** desde Gates, no entran en el array plano.

### Contexts

Capa independiente del RBAC: define qué áreas del sistema puede entrar el user.

```
user_contexts (user_id, context, tenant_id, organization_id?)
```

Con orgs activo, un user puede tener acceso a contextos distintos según la org:
- `(pepe, technician, tenant_1, org_a)` — técnico en org A
- `(pepe, backoffice, tenant_1, org_b)` — backoffice en org B

## Tablas RBAC

```
permissions        (id, name, description)
roles              (id, tenant_id?, organization_id?, name, description)
role_permissions   (role_id, permission_id)
model_roles        (model_type, model_id, role_id, organization_id?) ← polimórfico User|Team
model_permissions  (model_type, model_id, permission_id, organization_id?) ← polimórfico
entity_permissions (entity_type, entity_id, grantable_type, grantable_id, action) ← polimórfico
user_contexts      (user_id, context, tenant_id, organization_id?)
teams              (id, tenant_id, organization_id?, name, parent_team_id?)
team_members       (team_id, user_id, role_in_team [member|lead])
```

## Traits clave

Aplicables al User model:

```php
use Innertia\Auth\RBAC\Traits\HasRoles;
use Innertia\Auth\RBAC\Traits\HasContexts;
use Innertia\Platform\Traits\HasOrganization;    // si orgs ON
use Innertia\Platform\Teams\Traits\HasTeams;     // si teams ON
use Innertia\Platform\Traits\HasPreferences;

class User extends Authenticatable {
    use HasRoles, HasContexts, HasOrganization, HasTeams, HasPreferences;
}
```

Aplicables a modelos de negocio:

| Trait | Función |
|---|---|
| `HasTenant` | Global scope por tenant_id, auto-inject al crear |
| `HasOrganization` | Global scope por organization_id (cuando feature activo) |
| `HasUuid` / `HasNanoId` | IDs no auto-incrementales |
| `Auditable` | created_by / updated_by automático |
| `HasHistory` | Registra cambios en entity_history |
| `HasSingleFile` | Relación 1:1 con File |

## Endpoints estándar

| Endpoint | Acceso | Devuelve |
|---|---|---|
| `GET /status` | Público + X-Tenant | tenant info + features (organizations, teams, oauth, 2FA) + branding |
| `GET /ping` | Público + X-Tenant | Alias deprecated de /status (shape antiguo) |
| `GET /auth/me` | Privado | user + permissions + contexts + organizations + current_organization + teams + preferences (los últimos 3 condicionales al feature) |
| `GET /auth/me/permissions` | Privado | roles + permisos consolidados |
| CRUD `/organizations` | Privado | Solo si la app llamó `\Innertia\Platform\Organizations\Routes::register()` |
| CRUD `/teams` + `/teams/{id}/members` | Privado | Solo si la app llamó `\Innertia\Platform\Teams\Routes::register()` |
| `GET /preferences` | Privado | Todas las prefs del user |
| `GET /preferences/{module}` | Privado | Prefs con prefix `{module}.` |
| `PUT /preferences/{key}` | Privado | Upsert |
| `DELETE /preferences/{key}` | Privado | Eliminar |

Backward compat: `/auth/me/preferences*` siguen activas como aliases.

## Use Cases

```php
class CreateOrder extends \Innertia\Platform\Contracts\UseCase
{
    public function __construct(public readonly string $customerId) {}
    public function execute(): Order { /* ... */ }
}

$order = (new CreateOrder($id))->execute();
(new CreateOrder($id))->onQueue();          // async
```

En SaaS, el `tenant_key` se captura en construcción y se restaura en queue.

## Eventos

```php
class OrderCreated extends \Innertia\Platform\Events\DomainEvent {
    public function key(): string { return 'orders.created'; }
    public function audience(): array { return ['user:'.$this->order->customer_id]; }
}

event(new OrderCreated($order));
```

Broadcast vía Pusher/Reverb. Frontend recibe vía `useRealtime()`. `Subscription::matchesEvent('orders.*')` soporta wildcards.

## Configuración

```bash
php artisan vendor:publish --tag=innertia-config
php artisan vendor:publish --tag=innertia-routes  # si extiendes routes
php artisan migrate
```

Variables principales:
```env
INNERTIA_MODE=saas
INNERTIA_ORGANIZATIONS_ENABLED=false
INNERTIA_TEAMS_ENABLED=false
INNERTIA_API_DOMAIN=null
```

## Convenciones backend (productos que usan esta lib)

```
app/
├── Domains/{Domain}/
│   ├── Models/        # Eloquent
│   ├── UseCases/      # Lógica de negocio
│   ├── Services/      # Helpers
│   ├── Events/        # DomainEvents
│   ├── Listeners/     # Escuchas
│   ├── Gates/         # Autorización
│   ├── Enums/         # PHP 8.1+ backed enums
│   ├── Mails/         # InnertiaMailable
│   └── Observers/     # Eloquent observers
└── Apps/{AppName}/
    └── {Domain}/Controllers/  # Solo HTTP — delegan a UseCases
```

Reglas:
- Controllers solo validan y delegan a UseCases. Sin lógica de negocio.
- Models viven en `Domains/`, nunca en `app/Models/`.
- IDs UUID via `HasUuid` o nanoid via `HasNanoId`.
