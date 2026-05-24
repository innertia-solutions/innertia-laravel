---
name: innertia-permissions
description: Use when working with permissions, roles, RBAC, DomainGates, EntityPermission, HasRoles, HasApps, HasEntityPermissions, super_admin role, or `innertia:permissions` artisan command. Trigger for "asignar rol", "permiso por entidad", "rol por organization", "permission enum", "DomainGate", "user_apps", "Permissions::sync".
---

# Innertia — Permissions / RBAC

Sistema de autorización con múltiples fuentes consolidadas en runtime. Soporta scoping por tenant y por organization. Permisos opcionalmente por-entidad via `entity_permissions`.

## Tablas RBAC

```
permissions        (id, tenant_id?, name, description)
roles              (id, tenant_id?, organization_id?, name, description)
role_permissions   (role_id, permission_id)
model_roles        (model_type, model_id, role_id, organization_id?)        ← polimórfico
model_permissions  (model_type, model_id, permission_id, organization_id?)  ← polimórfico
entity_permissions (id, tenant_id?, entity_type, entity_id, grantable_type, grantable_id, action)
user_apps          (user_id, app, tenant_id, organization_id?)
```

`model_roles` es polimórfico: el `model_type` puede ser `User` o `Team` (cuando Teams está activo).

## Las 8 fuentes de permisos (orden lógico)

| # | Fuente | Cómo se asigna | Scope tenant | Scope org | Scope team |
|---|---|---|---|---|---|
| 1 | Direct grant | `$user->givePermission('users.view')` | tenant actual | org actual (si feature on) | n/a |
| 2 | Direct grant por org | `$user->givePermission('users.view', $orgId)` | tenant | org específica | n/a |
| 3 | Via rol del user | `$user->assignRole('admin')` | tenant | org actual (si feature on) | n/a |
| 4 | Via rol del user en org | `$user->assignRole('admin', $orgId)` | tenant | org específica | n/a |
| 5 | Via team membership | `$user->teams()->attach($team)` + team con rol | tenant | org del team | team |
| 6 | Via team con rol por org | `model_roles(team_id, role_id, org=X)` | tenant | org X | team |
| 7 | Entity grant directo | `EntityPermission::grant($entity, $user, 'edit')` | tenant | org del entity | n/a |
| 8 | Entity grant via team | `EntityPermission::grant($entity, $team, 'edit')` | tenant | org del entity | team |

`$user->resolvedPermissions()` y `/auth/me` consolidan:

```
direct grants ∪ permisos de roles del user ∪ permisos de roles de teams del user
filtered by OrganizationContext::scope() cuando organizations activo
```

`entity_permissions` se evalúan **por recurso** desde Gates, no entran en el array plano.

## Declarar permisos (config)

```php
// config/innertia.php
'super_admin_role' => 'super_admin',

'permissions' => [
    'backoffice' => [
        'label'       => 'Backoffice',
        'permissions' => [
            \App\Domains\Users\Permissions\UserPermissions::class,
            \App\Domains\Users\Permissions\RolePermissions::class,
            \App\Domains\Users\Permissions\SystemPermissions::class,
        ],
    ],
],
```

Cada clase puede ser un **enum backed** o un **constant container**:

```php
// Enum backed (recomendado)
enum UserPermissions: string {
    case View   = 'users.view';
    case Create = 'users.create';
    case Update = 'users.update';
    case Delete = 'users.delete';

    public function description(): string {
        return match ($this) {
            self::View   => 'Ver usuarios',
            self::Create => 'Crear usuarios',
            // ...
        };
    }
}

// Constant container (legacy, también soportado)
class UserPermissions {
    const VIEW   = 'users.view';
    const CREATE = 'users.create';
}
```

## Sincronizar permisos

```bash
# Crea los permisos faltantes en DB
php artisan innertia:permissions

# Además elimina los que ya no están en config
php artisan innertia:permissions --prune
```

Resultado: `Permissions::sync(prune: bool): array → {created, updated, skipped, deleted}`.

**No es obligatorio correrlo** — la app funciona lazily (crea permisos al asignarlos). Útil en CI para detectar drift entre código y DB.

## Super admin

```php
// config/innertia.php
'super_admin_role' => 'super_admin',
```

Un user con ese rol bypassa todos los gates y `hasPermission()` devuelve `true` para cualquier permission. Check programático:

```php
\Innertia\Facades\Permissions::isSuperAdmin($user);
```

## HasRoles trait — métodos clave

Aplicado al User base via `\Innertia\Auth\Models\User`:

```php
// Asignación
$user->assignRole('admin');                      // tenant-wide (o org actual si feature on)
$user->assignRole('admin', $orgId);              // específico a esa org
$user->removeRole('admin');
$user->syncRoles(['admin', 'editor']);           // reemplaza

// Lectura
$user->hasRole('admin');
$user->hasRole('admin', $orgId);
$user->getRoleNames();                            // Collection<string>
$user->roles();                                   // MorphToMany — con pivot organization_id si feature on

// Permisos directos (bypassa roles)
$user->givePermission('users.view');
$user->givePermission('users.view', $orgId);
$user->revokePermission('users.view');
$user->directPermissions();                       // MorphToMany

// Consolidado
$user->hasPermission('users.view');               // cache-aware, considera todas las fuentes
$user->hasPermission(UserPermissions::View);      // enum también funciona
```

## HasApps trait — contextos de acceso

`user_apps` define a qué áreas (backoffice, technician, pos, etc.) el user tiene acceso. El JWT lo valida en el Login.

```php
$user->appKeys();                                  // ['backoffice', 'technician']
$user->appKeysInOrganization($orgId);              // apps específicas a esa org
$user->accessibleOrganizationsByApp();             // ['backoffice' => [orgA, orgB], 'technician' => [orgC]]
$user->accessibleOrganizationIds();                // unique union de todas las orgs accesibles
$user->hasApp('backoffice');

$user->grantApp('backoffice');
$user->grantApp(['backoffice', 'pos'], $orgId);
$user->revokeApp('backoffice');
$user->revokeApp('backoffice', $orgId);
$user->syncApps(['backoffice', 'pos'], $orgId);
```

Combinación con orgs: un user puede ser técnico en org A y backoffice en org B.

## DomainGates (recomendado para autorización por entidad)

Extender `\Innertia\Platform\Contracts\DomainGate` con un método por ability. Se auto-registran en Laravel Gate.

```php
namespace App\Domains\Orders\Gates;

use App\Domains\Orders\Models\Order;
use App\Domains\Users\Models\User;
use Innertia\Platform\Contracts\DomainGate;

class OrdersGate extends DomainGate {
    public function manage(User $user, Order $order): bool {
        return $user->hasPermission('orders.manage')
            && $order->tenant_id === $user->tenant_id;
    }

    public function view(User $user, Order $order): bool {
        return $user->hasPermission('orders.view')
            || $order->isAccessibleBy($user, 'view');
    }
}
```

Registrar via `DomainServiceProvider`:

```php
namespace App\Domains\Orders;

use Innertia\Platform\Support\DomainServiceProvider;

class OrdersServiceProvider extends DomainServiceProvider {
    public function boot(): void {
        $this->registerGate(\App\Domains\Orders\Gates\OrdersGate::class);
    }
}
```

Convención de nombres:
- Clase: `{Domain}Gate` → prefijo `{domain-kebab}` (ej. `OrdersGate` → `orders`)
- Método público → ability `{prefix}.{method-kebab}` (`manage` → `orders.manage`)

Uso desde controller / código:

```php
Gate::authorize('orders.manage', $order);          // throws si falla
Gate::allows('orders.view', $order);               // bool
$this->authorize('orders.manage', $order);         // en un Controller con AuthorizesRequests
```

## EntityPermission (row-level)

Para grants directos a una entidad específica (`folder #42 accesible solo por user X`):

```php
use Innertia\Auth\RBAC\Models\EntityPermission;

EntityPermission::grant($folder, $user, 'edit');
EntityPermission::grant($folder, $team, 'view');
EntityPermission::revoke($folder, $user, 'edit');
EntityPermission::revokeAll($folder);
EntityPermission::check($folder, $user, 'edit');   // bool
```

### Trait HasEntityPermissions

Aplica a modelos de dominio:

```php
use Innertia\Platform\Traits\HasEntityPermissions;

class Folder extends Model {
    use HasEntityPermissions;
}

$folder->grantAccessTo($user, $anotherUser, action: 'edit');
$folder->grantAccessToRoles('editor', 'reviewer', action: 'view');
$folder->revokeAccessFrom($user);
$folder->revokeAllEntityAccess();
$folder->isAccessibleBy($user, 'edit');             // cascada: direct → role → owner
```

`isAccessibleBy` evalúa en orden:
1. Grant directo (entity_permissions where grantable=user)
2. Grant via roles del user
3. Grant via teams del user
4. Bypass super_admin

## /auth/me/permissions endpoint

```http
GET /auth/me/permissions
```

Response:

```json
{
  "roles": ["admin", "editor"],
  "permissions": ["users.view", "users.manage", "orders.view", ...]
}
```

`permissions` consolida: `direct ∪ via roles ∪ via teams` (filtrado por org scope si feature on).

## Patrones recomendados

- **Permisos coarse-grained en roles**, fine-grained con `entity_permissions`
- **Definir Gates** cuando el check requiera lógica sobre la entidad (ej. `$order->status === 'draft'`)
- **Usar `hasPermission()`** para checks simples sin contexto de entidad
- **Sync de permisos en CI** con `--prune` para evitar drift

## Skills relacionados

- `innertia-organizations` — scoping por org en roles/permisos
- `innertia-teams` — permisos vía team membership
- `innertia-config` — bloque `permissions` y `super_admin_role`
