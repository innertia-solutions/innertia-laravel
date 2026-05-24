# innertia-solutions/laravel-innertia

Framework interno de Innertia. Plataforma completa para backends Laravel single-app y SaaS:
auth + RBAC, organizations, teams, settings, gates, use cases, eventos realtime, exceptions,
mail, datatable, telemetry. Sin dependencias externas de multitenancy — manager propio.

```bash
composer require innertia-solutions/laravel-innertia
```

Auto-discovered por Laravel. Migrations cargan automáticamente.

---

## Tabla de contenidos

1. [Configuración](#configuración)
2. [Modos de operación](#modos-de-operación)
3. [SaaS / Multitenancy](#saas--multitenancy)
4. [Sistema de permisos](#sistema-de-permisos)
5. [Auth](#auth)
6. [Organizations](#organizations)
7. [Teams](#teams)
8. [Endpoints estándar](#endpoints-estándar)
9. [Use Cases](#use-cases)
10. [Gates (Domain Permissions)](#gates-domain-permissions)
11. [Settings](#settings)
12. [Eventos realtime](#eventos-realtime)
13. [Exceptions](#exceptions)
14. [Mail](#mail)
15. [DataTable](#datatable)
16. [Activity Logger](#activity-logger)
17. [Traits utilitarios](#traits-utilitarios)
18. [Releasing](#releasing)

---

## Configuración

```bash
php artisan vendor:publish --tag=innertia-config
php artisan vendor:publish --tag=innertia-routes  # solo si extiendes routes
php artisan migrate
```

Variables de entorno principales:

```env
INNERTIA_MODE=saas                       # 'app' | 'saas' | 'api'
INNERTIA_ORGANIZATIONS_ENABLED=false     # opt-in
INNERTIA_TEAMS_ENABLED=false             # opt-in
INNERTIA_API_DOMAIN=null                 # restringe resolución de tenants por subdominio
```

---

## Modos de operación

Controlado por `config('innertia.mode')`:

| Modo | Multitenancy | Auth | Use Cases | Eventos | Description |
|------|-------------|------|-----------|---------|-------------|
| `app` | ❌ | ✅ | ✅ | ✅ | Single-tenant. App interna. Sin X-Tenant header. |
| `saas` | ✅ | ✅ | ✅ | ✅ | Multi-tenant. Identificación vía X-Tenant header + scoping automático. |
| `api` | ❌ | ✅ (JWT) | ✅ | ✅ | API mode. Sin tenancy ni organizations (los API consumers manejan su scope). |

---

## SaaS / Multitenancy

Manager propio basado en `Innertia::tenant()` — sin dependencias externas (stancl/tenancy se eliminó).

### Cómo funciona

1. Frontend (o cliente API) envía `X-Tenant: <slug>` en cada request
2. Middleware `ResolveTenantFromHeader` busca el tenant por `key` y lo activa
3. Modelos con `HasTenant` aplican global scope automático por `tenant_id`
4. UseCases capturan el `tenant_key` en construcción y lo restauran al ejecutarse en queue

### API

```php
use Innertia\Facades\Innertia;

Innertia::tenant()           // Tenant|null — tenant activo
Innertia::activate('acme')   // activa manualmente (CLI/tinker/jobs)
Innertia::deactivate()
Innertia::withTenant('acme', fn() => ...)  // scope temporal
```

### Routes públicas vs privadas

- `routes/api.public.php` — sin auth (login, /status, OAuth callbacks)
- `routes/api.private.php` — con auth (resto del API)

### Trait `HasTenant`

```php
use Innertia\Saas\Traits\HasTenant;

class Invoice extends Model {
    use HasTenant;  // global scope por tenant_id, auto-fill al crear
}
```

---

## Sistema de permisos

RBAC completo basado en roles + permisos nombrados, con herencia opcional via teams y scoping
opcional por organización. Las tablas se crean por `php artisan migrate` (vienen en las migrations
del paquete).

### Estructura de tablas

```
permissions            (id uuid, name, description)
roles                  (id uuid, tenant_id?, organization_id?, name, description)
role_permissions       (role_id, permission_id)
model_roles            (model_type, model_id, role_id, organization_id?)   ← polimórfico
model_permissions      (model_type, model_id, permission_id, organization_id?) ← polimórfico
entity_permissions     (entity_type, entity_id, grantable_type, grantable_id, action) ← polimórfico
```

**Polimorfismo:**
- `model_roles.model_type` puede ser `User` **o `Team`** → roles asignables a equipos
- `entity_permissions.grantable_type` puede ser `User` **o `Team`** → grants directos sobre recursos a un equipo

### Combinaciones posibles de permisos

Tabla completa de cómo un usuario puede tener acceso a algo:

| # | Fuente del permiso | Cómo se asigna | Scope tenant | Scope org | Scope team | Ejemplo |
|---|---|---|---|---|---|---|
| 1 | **Direct grant** | `$user->givePermission('users.view')` | tenant actual | si activo, org actual | n/a | Pepe puede ver users en este tenant |
| 2 | **Direct grant por org** | `$user->givePermission('users.view', $orgId)` | tenant | org específica | n/a | Pepe puede ver users SOLO en org A |
| 3 | **Via rol del user** | `$user->assignRole('admin')` | tenant | org actual (si activo) | n/a | Pepe es admin → hereda permisos del rol admin |
| 4 | **Via rol del user en org** | `$user->assignRole('admin', $orgId)` | tenant | org específica | n/a | Pepe es admin SOLO en org A |
| 5 | **Via team membership** | `$user->teams()->attach($team)` + team tiene rol | tenant | org del team | team activo | Pepe en team Marketing → hereda roles del team |
| 6 | **Via team con rol por org** | team tiene `model_roles(team_id, role_id, org=X)` | tenant | org X | team | Team Marketing tiene rol editor en org A |
| 7 | **Entity grant directo** | `entity_permissions(entity, user_id, action)` | tenant | org del entity | n/a | Pepe tiene acceso al doc #42 específicamente |
| 8 | **Entity grant via team** | `entity_permissions(entity, team_id, action)` | tenant | org del entity | team | Team Operations tiene edit-access a folder X |

### Resolución de permisos en runtime

Cuando consultas `$user->resolvedPermissions()` o `/auth/me`, el sistema **consolida** todas las fuentes:

```
resolved = direct grants ∪ permisos de roles del user ∪ permisos de roles de teams del user
         filtered by OrganizationContext::scope() cuando organizations está activo
```

Los `entity_permissions` se consultan **caso por caso** desde Gates (no entran en el array plano de permisos),
porque dependen del recurso específico que se accede.

### Apps y contextos

Capa independiente de los permisos: define **a qué áreas del sistema puede entrar un usuario**.

```
user_apps              (user_id, app, tenant_id, organization_id?)
```

Tabla con un row por (user, app, [organization]). El user "puede entrar a la app backoffice" — los
permisos finos los resuelve el RBAC anterior. Con organizations activo, un user puede tener acceso
a apps distintas según la org.

```php
$user->grantApp('backoffice')                  // sin org
$user->grantApp('technician', organizationId: 5)
$user->appKeys()                                // ['backoffice', 'technician'] (en scope actual)
$user->appKeysInOrganization(5)                // apps que tiene en org 5
$user->accessibleOrganizationsByApp()          // { backoffice: [1,2], technician: [5] }
```

### Helpers en User model

```php
use Innertia\Auth\RBAC\Traits\HasRoles;
use Innertia\Auth\RBAC\Traits\HasApps;
use Innertia\Platform\Traits\HasOrganization;       // si orgs activo
use Innertia\Platform\Teams\Traits\HasTeams;        // si teams activo

class User extends Authenticatable {
    use HasRoles, HasApps, HasOrganization, HasTeams;
}
```

### Cache

Permisos y apps tienen cache por TTL configurable (`innertia.cache.ttl` minutos, default 60). El
cache key incluye `tenant_id` y un fingerprint del `OrganizationContext::scope()` para no servir
resultados stale cuando el user cambia de org.

---

## Auth

JWT-based. Configurable, soporta OTP, 2FA, email verification, OAuth (Google/Microsoft/GitHub),
password recovery, demo mode.

### Configuración

Vive en `config('innertia.auth')` y en `settings` de DB (publicables por tenant).

### Endpoints incluidos

```
POST   /auth/login                     # email + password
POST   /auth/logout
POST   /auth/refresh
GET    /auth/me                        # identidad completa (ver sección)
GET    /auth/me/permissions            # roles + permisos consolidados
POST   /auth/otp/send
POST   /auth/otp/verify
POST   /auth/2fa/enable
POST   /auth/2fa/disable
POST   /auth/2fa/verify
POST   /auth/email/verify/send
GET    /auth/email/verify
POST   /auth/password/change
POST   /auth/password/set
POST   /auth/password/forgot
POST   /auth/password/reset
GET    /auth/{provider}/redirect       # OAuth: google|microsoft|github
GET    /auth/{provider}/callback
```

### Flujos de login

| Flujo | Trigger | Pasos |
|---|---|---|
| **A — Estándar** | features mínimas | login → token |
| **B — OTP enabled** | tenant tiene OTP on | login → 200 `{ otp_required: true }` → verify OTP → token |
| **C — Force password change** | admin seteo `force_password_change=true` | login → 200 `{ requires_password_change: true }` → change → token |
| **D — Email verification** | invitación sin password | invitación con token → set password → email verify → token |
| **E — Force change + email verify** | combo | login → password change → email verify → token |
| **F — Todo activo** | features completas | login + 2FA + OTP + email verify |

### Flags del User

| Campo | Significado |
|---|---|
| `force_password_change` | Próximo login pide cambio |
| `two_factor_enabled` | 2FA TOTP activo |
| `email_verified_at` | Email confirmado |
| `seen_at` | Última actividad |

---

## Organizations

Sub-tenant scoping. Opt-in via `innertia.organizations.enabled`. Cada tenant puede tener N orgs;
modelos con `HasOrganization` se filtran automáticamente por la org activa.

### Activación

```bash
INNERTIA_ORGANIZATIONS_ENABLED=true
php artisan innertia:organization:install
php artisan migrate
```

El install crea:
- Tabla `organizations`
- Agrega `organization_id` (nullable) a las tablas declaradas en `innertia.organizations.tables`
- Agrega `organization_id` a `roles`, `model_roles`, `model_permissions`, `user_apps` (RBAC + identity scoping)

### Headers

```
X-Organization: <slug>     # org activa (writes + reads scope)
X-Consolidated: true       # opcional: scope expandido a todas las orgs accesibles del user
```

### Conceptos

```php
Innertia::organization()->current()   // int|null — UNA org para writes
Innertia::organization()->scope()     // array<int> — set de org ids para reads
Innertia::organization()->withOrganization(5, fn() => ...)
```

`current` se usa al crear (HasOrganization auto-inyecta). `scope` se usa al leer (global scope).
Default: `scope = [current]`. En vista consolidada: `scope` puede contener N orgs (el set que el
user puede ver).

### Trait `HasOrganization`

```php
use Innertia\Platform\Traits\HasOrganization;

class Asset extends Model {
    use HasTenant, HasOrganization;
}

// Al crear: organization_id = current() automáticamente
// Al leer: WHERE organization_id IN (scope())
```

### Middlewares

- `organization.resolve` — lee `X-Organization` y popula context
- `organization.require` — 400 si no hay org activa cuando se requiere

### Rutas CRUD (opt-in)

El paquete trae un controller default y un helper para montar las rutas:

```php
// routes/api.private.php
Route::middleware(['auth:api', 'tenant.require'])->group(function () {
    \Innertia\Platform\Organizations\Routes::register();
    // GET/POST     /organizations
    // GET/PUT/DELETE /organizations/{id}
});
```

Argumentos opcionales: `Routes::register(prefix: 'admin/orgs', controller: \App\OrgsController::class)`.

### Extender el modelo y agregar campos propios

Patrón en 5 pasos para, por ejemplo, agregar `owner_id`:

```php
// 1. Migration de la app
Schema::table('organizations', fn ($t) => $t->uuid('owner_id')->nullable());

// 2. Modelo extendido
class Organization extends \Innertia\Platform\Organizations\Models\Organization {
    protected $fillable = [...parent::$fillable, 'owner_id'];
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
}

// 3. config('innertia.organizations.model') = App\Models\Organization::class

// 4. Controller extendido — hooks `extraStoreRules`, `extraUpdateRules`, `extraFields`
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
Routes::register('organizations', \App\Http\OrganizationsController::class);
```

### Niveles de extensibilidad

| Necesidad | Mecanismo |
|---|---|
| Agregar columnas y validaciones | Hooks `extraFields` + `extraStoreRules` en controller |
| Cambiar mapping de atributos (rename, transform, defaults) | Extender UseCase + override `attributes()` |
| Side-effects post-create (eventos, notifs, queues) | Extender UseCase + override `execute()` |
| Reemplazar UseCase entero | Subclase + container bind + override método del controller que lo llama |
| Reemplazar controller entero | Forkear y montar via `Routes::register('orgs', App\Controller::class)` |

### Artisan commands

```bash
php artisan innertia:organization:create {tenant} {key} {name} [--inactive]
php artisan innertia:organization:list [--tenant=]
php artisan innertia:organization:install [--force]   # --force genera incremental cuando cambias config.tables
php artisan innertia:organization:check               # verifica coherencia trait ↔ config ↔ schema
```

---

## Teams

Agrupación de usuarios para asignación colectiva de roles y permisos. Opt-in via `innertia.teams.enabled`.
**Independiente de Organizations** — teams pueden ser tenant-wide (sin orgs) o org-scoped (con orgs).

### Activación

```bash
INNERTIA_TEAMS_ENABLED=true
php artisan innertia:teams:install
php artisan migrate
```

Crea tablas:
- `teams (id uuid, tenant_id, organization_id NULLABLE, parent_team_id, name, description)`
- `team_members (team_id, user_id, role_in_team [member|lead], joined_at)`

### Combinaciones

| Orgs | Teams | Resultado |
|---|---|---|
| OFF | OFF | Solo users + roles individuales |
| OFF | ON | Teams a nivel tenant. Pattern típico para SaaS pequeño con grupos de permisos |
| ON | OFF | Multi-org con users sueltos. Cada user permisos individuales |
| ON | ON | Enterprise. Teams pueden ser tenant-wide (`organization_id=NULL`) o por org |

### Trait `HasTeams`

El trait ya se aplica automáticamente en `\Innertia\Auth\Models\User` (la base) — cuando el feature está disabled es no-op y no agrega overhead. Si tenés tu propio User que NO extiende la base, agrégalo manualmente:

```php
use Innertia\Platform\Teams\Traits\HasTeams;

class User extends Authenticatable {
    use HasTeams;
}

$user->teams()                  // BelongsToMany
$user->teamIds()                // array<string>
$user->rolesViaTeams()          // Collection<Role>
$user->permissionsViaTeams()    // array<string>
```

### Asignar roles a teams

```php
$team = Team::find('...');
$team->assignRole('editor');   // ahora todos los members heredan permisos de 'editor'
```

### Recursos a teams

`entity_permissions` es polimórfico, así que se puede dar acceso directo a una entidad a un team:

```php
// Dar acceso de edit al folder #42 al team Marketing
EntityPermission::create([
    'entity_type'   => Folder::class,
    'entity_id'     => 42,
    'grantable_type' => Team::class,
    'grantable_id'  => $marketingTeam->id,
    'action'        => 'edit',
]);
```

Todos los miembros del team heredan ese acceso vía resolución de gates.

### Rutas CRUD (opt-in)

```php
Route::middleware(['auth:api', 'tenant.require'])->group(function () {
    \Innertia\Platform\Teams\Routes::register();
    // GET/POST     /teams
    // GET/PUT/DELETE /teams/{id}
    // PUT          /teams/{id}/members   { members: [{user_id, role_in_team}] }
});
```

`Routes::register(prefix, controller)` para customizar prefijo o controller.

### Extender Team con campos propios

Mismo patrón que Organizations. Hooks disponibles en el controller:

- `extraStoreRules()` / `extraUpdateRules()` — reglas de validación adicionales
- `extraFields(Request, ?Team)` — mapping request → atributos del modelo
- `showRelations()` — relaciones eager-load en `GET /teams/{id}`

Ejemplo agregando `color` y `avatar`:

```php
class TeamsController extends \Innertia\Platform\Teams\Http\Controllers\TeamsController {
    protected function extraStoreRules(): array {
        return [
            'color'  => 'nullable|string|max:32',
            'avatar' => 'nullable|string|max:100',
        ];
    }
    protected function extraUpdateRules(): array { return $this->extraStoreRules(); }
    protected function extraFields(Request $r, $team = null): array {
        return array_filter(
            ['color' => $r->input('color'), 'avatar' => $r->input('avatar')],
            fn ($v) => $v !== null,
        );
    }
    protected function showRelations(): array {
        return [...parent::showRelations(), 'members.worker:id,user_id,position'];
    }
}
```

El `SyncTeamMembers` UseCase preserva el `joined_at` original de los miembros existentes — solo se actualiza `role_in_team` cuando cambia.

### Artisan commands

```bash
php artisan innertia:team:create {tenant} {name} [--description=] [--parent=] [--org=]
php artisan innertia:team:list [--tenant=]
php artisan innertia:teams:install [--force]
```

---

## Endpoints estándar

Disponibles automáticamente. Combinables con las routes propias de cada producto.

### Estado del tenant

```http
GET /status                            # con header X-Tenant
```

Response:
```json
{
  "ok": true,
  "tenant": { "id": 1, "key": "acme", "name": "Acme", "status": "active", "isActive": true },
  "features": {
    "organizations": true,
    "teams": false,
    "twoFactor": true,
    "oauth": ["google", "microsoft"]
  },
  "branding": { "demo": { "email": "demo@acme.com", "password": "demo123" } }
}
```

`GET /ping` queda como alias deprecated (shape antiguo) por backward compat.

### Identidad

```http
GET /auth/me                          # con auth
```

Response:
```json
{
  "user": { "id": "...", "name": "...", "email": "...", "apps": [...] },
  "permissions": ["users.view", "users.manage", ...],
  "contexts": ["backoffice", "technician"],
  "organizations": {
    "backoffice": [{ "id": 1, "key": "acme-cl", "name": "Acme Chile" }],
    "technician": [
      { "id": 1, "key": "acme-cl", "name": "Acme Chile" },
      { "id": 2, "key": "acme-pe", "name": "Acme Perú" }
    ]
  },
  "current_organization": { "id": 1, "key": "acme-cl", "name": "Acme Chile", "active": true },
  "teams": [
    { "id": "uuid-...", "name": "Comité de Calidad", "parent_team_id": null, "organization_id": 1, "role_in_team": "lead" }
  ],
  "preferences": { "appearance": "dark", "language": "es" }
}
```

- `contexts` reemplaza a `availableContexts` (mantenido como alias deprecated)
- `organizations` solo aparece cuando OrganizationsFeature está activo
- `current_organization` aparece solo cuando el request trajo `X-Organization` válido
- `teams` aparece solo cuando TeamsFeature está activo

### Preferencias del usuario

```http
GET    /preferences                   # todas las prefs públicas
GET    /preferences/{module}          # filtradas por prefix '{module}.*'
PUT    /preferences/{key}             # upsert
DELETE /preferences/{key}             # eliminar
```

Convención: las keys se nombran `{module}.{key}` (ej. `tables.workers.columns`, `dashboard.layout`).
`GET /preferences/tables` devuelve solo las del módulo tables, con keys sin el prefix.

Las rutas viejas `/auth/me/preferences*` siguen activas como aliases deprecated.

---

## Use Cases

Capa de lógica de negocio. Extienden `\Innertia\Platform\Contracts\UseCase`. Reciben parámetros en
constructor, devuelven resultado desde `execute()`. Soportan ejecución sincrónica o en queue.

```php
namespace App\Domains\Orders\UseCases;

use App\Domains\Orders\Models\Order;
use Innertia\Platform\Contracts\UseCase;

class CreateOrder extends UseCase
{
    public function __construct(
        public readonly string $customerId,
        public readonly float  $total,
    ) {}

    public function execute(): Order {
        return Order::create([
            'customer_id' => $this->customerId,
            'total'       => $this->total,
        ]);
    }
}
```

Ejecución:

```php
// Sincrónico:
$order = (new CreateOrder('...', 99.9))->execute();

// Cola:
(new CreateOrder('...', 99.9))->onQueue();
(new CreateOrder('...', 99.9))->onQueue('critical');
(new CreateOrder('...', 99.9))->delay(now()->addMinutes(5));
```

**SaaS:** el tenant_key se captura en el constructor y se restaura automáticamente en queue.

---

## Gates (Domain Permissions)

Gates de Laravel reescritos como clases en `app/Domains/{Domain}/Gates/`. Extienden
`\Innertia\Platform\Contracts\DomainGate`.

```php
namespace App\Domains\Orders\Gates;

use App\Domains\Orders\Models\Order;
use App\Domains\Users\Models\User;
use Innertia\Platform\Contracts\DomainGate;

class ViewOrder extends DomainGate
{
    public function check(User $user, Order $order): bool {
        return $user->hasPermission('orders.view')
            && $user->id === $order->customer_id;
    }
}
```

Uso:

```php
Gate::authorize('view-order', $order);
```

Gates se autodescubren via convención de namespace.

---

## Settings

Configuración por tenant, persistida en DB. Cast automático (`string`, `boolean`, `integer`, `json`,
`encrypted`).

```php
use Innertia\Facades\Settings;

Settings::set('auth.otp_enabled', true, 'boolean');
Settings::get('auth.otp_enabled');                 // bool
Settings::get('auth.otp_enabled', false);          // con default
Settings::scope('auth.*')->toArray();              // todas las claves del scope
```

Settings públicas (visibles al frontend) usan `Settings::setPublic(...)`.

---

## Eventos realtime

`DomainEvent` extensible para events que se broadcast a usuarios.

```php
use Innertia\Platform\Events\DomainEvent;

class OrderCreated extends DomainEvent
{
    public function key(): string {
        return 'orders.created';
    }

    public function audience(): array {
        return ['user:'.$this->order->customer_id];
    }
}
```

El sistema:
- Dispara via `event(new OrderCreated($order))`
- Broadcast vía Pusher / Reverb / canal configurado
- Frontend `useRealtime()` recibe y reacciona

`Subscription::matchesEvent('orders.*')` soporta wildcards dot-notation.

---

## Exceptions

`InnertiaExceptionHandler::register($exceptions)` — register handler global en
`bootstrap/app.php`. Convierte:

- `ConflictException` → 409
- `NotFoundException` → 404
- `ValidationException` (Innertia) → 422 con shape `{ errors: { field: [msg] } }`
- `PermissionException` → 403
- Resto → 500 con trace en debug

---

## Mail

`InnertiaMailable` extiende `Mailable` con:
- Branding del tenant (logo, colors via `tenant.configs`)
- Templates Blade con layout `mail::layouts.tenant`
- Settings de SMTP por-tenant

```php
namespace App\Domains\Orders\Mails;

use Innertia\Mail\InnertiaMailable;

class OrderConfirmation extends InnertiaMailable {
    public function build() {
        return $this->view('mails.order-confirmation')
            ->subject('Tu pedido fue confirmado');
    }
}
```

---

## DataTable

```php
use Innertia\Facades\DataTable;

$result = DataTable::create('users')
    ->query(User::query())
    ->columns(['id', 'name', 'email', 'created_at'])
    ->searchable(['name', 'email'])
    ->make();
```

Soporta paginación server-side, sort por columna, filtros por columna, search, export (xlsx/csv/pdf/json).

---

## Activity Logger

```php
use Innertia\Facades\ActivityLogger;

ActivityLogger::logUserAction('login');
ActivityLogger::logEntityAction('updated', 'invoice', $invoice->id, 'Cambió el monto');
ActivityLogger::logSecurityAction('password_changed');
```

Persiste en `activity_log` table. Visible vía `EntityHistory` trait.

---

## Traits utilitarios

| Trait | Descripción |
|---|---|
| `HasNanoId` | Nano IDs en vez de auto-increment PKs |
| `HasUuid` | UUIDs |
| `HasHistory` | Auto-record de cambios en `entity_history` |
| `Auditable` | Track `created_by` / `updated_by` automáticamente |
| `HasTenant` | Global scope + auto-inject por tenant (SaaS mode) |
| `HasOrganization` | Global scope + auto-inject por organization (cuando feature activo) |
| `HasApps` | Tabla user_apps + appKeys() + grantApp/revokeApp |
| `HasRoles` | RBAC roles, permissions |
| `HasTeams` | Membership a teams + permission inheritance |
| `HasPreferences` | Preferencias del user (key/value con cast) |
| `HasSingleFile` | Relación 1:1 con File (avatar, logo, etc.) |
| `UseEnumWithValues` | Helpers para enums PHP 8.1+ con `values()`, `labels()`, `options()` |

---

## Claude Code skills

El paquete trae un set de skills (`.md` con frontmatter YAML) que documentan cada feature para Claude Code. Cada proyecto que consume el paquete los instala con:

```bash
php artisan innertia:skills:install
# → instala en .claude/skills/innertia/
```

Skills incluidos:

| Skill | Cuándo se activa |
|---|---|
| `innertia-framework` | overview general, modes, estructura DDD |
| `innertia-organizations` | trabajo con Organizations, multi-org scoping |
| `innertia-teams` | trabajo con Teams, members, RBAC por grupo |
| `innertia-config` | edición de config/innertia.php |
| `innertia-storage` | HasSingleFile, HasFiles, disks |
| `innertia-extending` | extender modelos/controllers/UseCases del paquete |

```bash
# Sobrescribir skills existentes (p.ej. tras composer update del paquete)
php artisan innertia:skills:install --force

# Cambiar destino
php artisan innertia:skills:install --path=.claude/skills/platform
```

Se recomienda **commitear `.claude/skills/innertia/`** al repo del proyecto para que el equipo comparta el mismo contexto de Claude.

## Releasing

Workflow `Release` de GitHub Actions (`workflow_dispatch`). Elegir `patch`, `minor` o `major`. El
workflow crea + pushea el tag — Packagist se actualiza vía webhook.

---

## License

MIT
