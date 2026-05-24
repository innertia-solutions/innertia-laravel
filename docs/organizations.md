# Organizations (opt-in)

`innertia-laravel` ships an optional second-level scoping layer that sits ON TOP of the existing Tenant layer. Until you flip the master switch, the library behaves exactly as it did before 0.3.0.

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
| `saas`, org disabled          | tenant uuid       | always NULL              | always NULL                    |
| `saas`, org enabled (global)  | tenant uuid       | NULL (tenant-wide)       | NULL (applies in every org)    |
| `saas`, org enabled (scoped)  | tenant uuid       | numeric org id           | numeric org id                 |

## Consolidated view

Send `X-Consolidated: true` together with `X-Organization`. The middleware will populate `scope()` from `auth()->user()->accessibleOrganizationIds()`. Implement that method on your User model to return the int ids the user can read.

## CI check

Run during CI to catch missing migrations or stale config:

```bash
php artisan innertia:organization:check
```

## Turning it off

Set `organizations.enabled = false` and the library reverts to pre-0.3.0 behaviour. Migrations stay (columns are nullable), traits become no-ops.
