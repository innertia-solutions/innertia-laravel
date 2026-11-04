# Organizations (opt-in)

`innertia-laravel` ships an optional second-level scoping layer that sits ON TOP of the existing Tenant layer. Until you flip the master switch, the library behaves exactly as it did before 0.3.0.

## Mode compatibility

Organizations work in `app`, `saas` and **`open`** modes (in `open` an org is resolved only once a tenant is active; before tenant selection `X-Organization` is ignored). **In `api` mode the feature is forcibly inactive** — API consumers are expected to handle their own isolation. Setting `organizations.enabled=true` in `api` mode is a no-op: traits, middleware, commands, and the facade all behave as if the feature were disabled.

## When you want this

You need it when a single tenant has multiple **independent business units** that:

- Must not see each other's data by default.
- Have their own role assignments (a user can be `admin` of org A and `viewer` of org B within the same tenant).
- Sometimes need to be queried together (consolidated dashboards).

## Enable the feature

1. Publish (or update) the package config:

   ```bash
   php artisan vendor:publish --tag=innertia-config --force
   ```

2. Edit `config/innertia.php`:

   ```php
   'organizations' => [
       'enabled'    => true,
       'tables'     => ['documents', 'projects', 'invoices'], // your domain tables
       'column'     => 'organization_id',
       'with_index' => true,
       // Defaults to the model shipped by the library — only override
       // when you need to extend it.
       // 'model'   => App\Models\Organization::class,
   ],
   ```

3. (Optional) Extend the library's `Organization` model in your app. The lib already
   ships a working model + migration; most apps just need it as-is. Extend only
   when you want to add relations, scopes, or custom behaviour:

   ```php
   namespace App\Models;

   class Organization extends \Innertia\Platform\Organizations\Models\Organization
   {
       // Add your relations / casts / scopes here.
   }
   ```

   Then point the config at your subclass:

   ```php
   'model' => App\Models\Organization::class,
   ```

   Apps that prefer to type by interface or fully replace the model can implement
   `Innertia\Platform\Contracts\OrganizationContract` directly instead of extending.

4. Run the migrations (the `organizations` table migration is auto-loaded by the
   library — it ships as part of the saas migrations). To customise the schema,
   publish it first with `php artisan vendor:publish --tag=innertia-migrations`.

   Then generate the per-table install migration that adds `organization_id` to
   your domain tables:

   ```bash
   php artisan innertia:organization:install
   php artisan migrate
   ```

5. Add the trait to every domain model:

   ```php
   use Innertia\Platform\Traits\HasTenant;
   use Innertia\Platform\Traits\HasOrganization;

   class Document extends Model {
       use HasTenant, HasOrganization;
   }
   ```

6. Protect routes:

   ```php
   Route::middleware(['auth:api', 'tenant.resolve', 'tenant.require',
                      'organization.resolve', 'organization.require'])
        ->group(fn () => require __DIR__ . '/api.private.php');
   ```

7. Set the header on the client:

   ```http
   X-Tenant: acme
   X-Organization: north-america
   ```

## Access to an organization

Single rule, enforced by `ResolveOrganizationFromHeader` (also mounted on the authenticated `/auth/*` group):

- **Who can enter org X** (of the active tenant): a user with a `user_contexts` row in that tenant whose `organization_id` is `X` **or `NULL`** (`NULL` = every org of the tenant).
- **What they can do there**: roles in `model_roles` with `organization_id = X` or `NULL` (global within the tenant).
- Inactive org → `403 {"error":"organization_inactive"}`. Not a member → `403 {"error":"organization_forbidden"}`. `is_platform_admin` users skip the membership check. Requests without an authenticated user (public routes) are not membership-checked.

Helpers (shipped in `HasContexts` / `HasRoles` — no need to implement them on your User):

```php
$user->accessibleOrganizationIds();     // int[] — active orgs of the active tenant the user can enter
$user->canAccessOrganization($orgId);   // bool
$user->grantContext('backoffice', $orgId);   // member of one org (null = all orgs)
$user->assignRole('operator', $orgId);       // same role can live in several orgs
$user->removeRole('operator', $orgId);       // removes only that org (without $orgId: all)
```

`/auth/me` returns `current_organization` (from `X-Organization`) and `permissions` filtered to global roles + roles of the active org (`/auth/me/permissions` too).

`innertia:organization:install` also rebuilds the uniqueness of `model_roles` (drops the 3-column primary key → `model_roles_scoped_unique` incl. `organization_id`) and `user_contexts` (→ `user_contexts_scoped_unique`), so the same role/context can be granted in several orgs. In Postgres `NULL` never collides in a unique index; idempotency of global rows is guaranteed by `assignRole()` / `grantContext()`.

## Reading vs writing

```php
Innertia::organization()->current();       // ?int   — for WRITES
Innertia::organization()->scope();         // int[]  — for READS (default [current()])
Innertia::organization()->setScope([1,2]); // consolidated view
Innertia::organization()->withOrganization(99, fn () => ...); // scoped run
```

## Permissions in three modes

| Mode                          | `roles.tenant_id` | `roles.organization_id` | `model_roles.organization_id` |
|-------------------------------|-------------------|--------------------------|--------------------------------|
| `app`                         | always NULL       | always NULL              | always NULL                    |
| `app`, org enabled            | always NULL       | NULL or numeric          | NULL or numeric                |
| `saas`, org disabled          | tenant uuid       | always NULL              | always NULL                    |
| `saas`, org enabled (global)  | tenant uuid       | NULL (tenant-wide)       | NULL (applies in every org)    |
| `saas`, org enabled (scoped)  | tenant uuid       | numeric org id           | numeric org id                 |
| `api` (any setting)           | always NULL       | always NULL              | always NULL                    |

## Consolidated view

Send `X-Consolidated: true` together with `X-Organization`. The middleware populates `scope()` with `auth()->user()->accessibleOrganizationIds()` (provided by `HasContexts`): the active orgs of the tenant the user can enter.

## CI check

Run during CI to catch missing migrations or stale config:

```bash
php artisan innertia:organization:check
```

## Turning it off

Set `organizations.enabled = false` and the library reverts to pre-0.3.0 behaviour. Migrations stay (columns are nullable), traits become no-ops.
