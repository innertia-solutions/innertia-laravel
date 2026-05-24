---
name: innertia-config
description: Use when editing or referencing config/innertia.php — modes, tenancy, organizations, teams, auth, permissions, mail, exports. Trigger for questions about config keys, env vars (INNERTIA_*), or "qué config necesito para activar X feature".
---

# config/innertia.php — referencia

Archivo central de configuración del paquete. Publicado via `php artisan vendor:publish --tag=innertia-config`.

## Bloques principales

### `mode`

```php
'mode' => 'saas',   // 'app' | 'saas' | 'api'
```

| Modo | Tenancy | Auth | Orgs/Teams pueden activarse |
|------|---|---|---|
| `app` | ❌ | ✅ | ✅ |
| `saas` | ✅ X-Tenant | ✅ JWT | ✅ |
| `api` | ❌ | ✅ JWT | ❌ (forzosamente off) |

### `apps`

Contextos del Login UseCase. El JWT valida que el user tenga acceso al app antes de emitir token.

```php
'apps' => [
    'backoffice' => 'Administración',
    'technician' => 'Técnicos',
],
```

### `saas`

```php
'saas' => [
    'tenant_model' => \Innertia\Saas\Models\Tenant::class,   // override aquí si extendés
    'db_strategy'  => 'single',                              // 'single' | 'multi'
    'db_prefix'    => 'mydb_',                               // solo si multi
    'central_domains' => ['localhost', '127.0.0.1'],
],
```

### `organizations` (opt-in)

```php
'organizations' => [
    'enabled'    => env('INNERTIA_ORGANIZATIONS_ENABLED', false),
    'tables'     => ['documents', 'findings', 'projects'],   // las que reciben organization_id
    'column'     => 'organization_id',
    'with_index' => true,                                     // crea (tenant_id, organization_id) compuesto
    'model'      => \Innertia\Platform\Organizations\Models\Organization::class,
],
```

Después de editar `tables`, correr `php artisan innertia:organization:install --force && php artisan migrate`.

### `teams` (opt-in)

```php
'teams' => [
    'enabled' => env('INNERTIA_TEAMS_ENABLED', false),
    'model'   => \Innertia\Platform\Teams\Models\Team::class,
],
```

### `super_admin_role`

```php
'super_admin_role' => 'super_admin',   // rol que bypassa todos los gates
```

### `permissions`

Declarar enums de permisos por app. Run `php artisan innertia:permissions` para sincronizar.

```php
'permissions' => [
    'backoffice' => [
        'label' => 'Backoffice',
        'permissions' => [
            \App\Domains\Users\Permissions\UserPermissions::class,
            \App\Domains\Users\Permissions\RolePermissions::class,
        ],
    ],
],
```

Cada clase es un enum o constant container que declara strings:

```php
class UserPermissions {
    const VIEW   = 'users.view';
    const CREATE = 'users.create';
    const UPDATE = 'users.update';
    const DELETE = 'users.delete';
}
```

### `auth`

```php
'auth' => [
    'user_model' => \App\Domains\Users\Models\User::class,   // donde vive tu User

    'email_verification' => ['enabled' => false, 'ttl' => 60],
    'otp' => ['enabled' => false, 'ttl' => 10],
    '2fa' => ['enabled' => false],
    'sessions' => ['restrict_concurrent' => false],
],
```

**Importante**: estos flags son **defaults a nivel código**. En runtime, el auth layer lee del sistema Settings (por-tenant). Para activar live en un tenant:

```php
Settings::set('auth.otp.enabled', true);
Settings::set('auth.email_verification.enabled', true);
Settings::set('auth.sessions.restrict_concurrent', true);
```

### `mail`

```php
'mail' => [
    'logo_url'    => env('MAIL_LOGO_URL'),
    'brand_color' => env('MAIL_BRAND_COLOR', '#6366f1'),
],
```

### `exports`

```php
'exports' => [
    'disk'    => env('EXPORT_DISK', env('FILESYSTEM_CLOUD', 'local')),
    'handler' => \App\Exports\ExportTenantData::class,   // tu subclass de TenantExport
],
```

## Variables de entorno relevantes

```env
INNERTIA_MODE=saas
INNERTIA_ORGANIZATIONS_ENABLED=true
INNERTIA_TEAMS_ENABLED=true
INNERTIA_API_DOMAIN=null

MAIL_LOGO_URL=
MAIL_BRAND_COLOR=#6366f1

EXPORT_DISK=s3
```

## Tips para mantener el config

- **No agregar lógica al config** — solo claves y modelos. Lógica condicional en runtime via `OrganizationsFeature::isActive()`, `TeamsFeature::isActive()`.
- **Cuando agregás tablas a `organizations.tables`**, correr `innertia:organization:install --force` para generar migration incremental + `migrate`.
- **Cuando cambiás el modelo** (`organizations.model` / `teams.model` / `auth.user_model`), correr `composer dump-autoload`.
- **El config se cachea** en producción (`php artisan config:cache`). Al cambiar config en dev/CI, asegurarse que el cache se rebuilde.

## Skills relacionados

- `innertia-organizations` — uso del bloque organizations
- `innertia-teams` — uso del bloque teams
- `innertia-framework` — modes
