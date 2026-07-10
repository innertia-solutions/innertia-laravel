<?php

namespace Innertia\Auth\RBAC\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Innertia\Auth\RBAC\Models\Permission;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Platform\Organizations\OrganizationsFeature;

/**
 * Manages named (app-level) permissions from config + the permission cache.
 *
 * ── Permission definitions ────────────────────────────────────────────────────
 * Permissions live in CODE (config/enums), not in the DB.
 * The DB is populated lazily — no artisan command required before the app works.
 * `sync` is an optional deploy step to keep descriptions in DB up to date.
 *
 * Config formats (mix freely):
 *   1. Classic array:
 *      ['category' => 'users', 'category_alias' => 'Usuarios', 'permissions' => [
 *          'users.view' => 'Ver lista de usuarios',
 *      ]]
 *
 *   2. Enum class (recommended):
 *      \App\Enums\UserPermissions::class
 *      enum UserPermissions: string {
 *          case View = 'users.view';
 *          public function description(): string { return 'Ver lista de usuarios'; }
 *      }
 *
 * ── Cache ─────────────────────────────────────────────────────────────────────
 * Permission checks are cached per user (+ tenant in SaaS).
 * Cache is busted automatically when roles/permissions change via the library.
 * TTL is configured in config('innertia.cache.ttl') — default 60 minutes.
 *
 * Cache keys:
 *   innertia.perms.{userId}              (app mode)
 *   innertia.perms.{tenantId}.{userId}   (SaaS mode)
 */
class PermissionsService
{
    // ── Cache ─────────────────────────────────────────────────────────────────

    /**
     * Resolve and cache all permission names for a user.
     * Merges permissions from roles + direct grants.
     */
    public function getUserPermissions(Authenticatable $user): array
    {
        $key = $this->cacheKey((string) $user->getAuthIdentifier());

        $ttl = config('innertia.cache.ttl', 60);

        $loader = function () use ($user) {
            if (! method_exists($user, 'roles')) {
                return [];
            }

            $rolesQuery = $user->roles()->with('permissions');

            if (OrganizationsFeature::isActive()) {
                $orgId = \Innertia\Facades\Innertia::organization()?->current();
                $rolesQuery->where(function ($q) use ($orgId) {
                    $q->whereNull('model_roles.organization_id');
                    if ($orgId !== null) {
                        $q->orWhere('model_roles.organization_id', $orgId);
                    }
                });
            }

            $viaRoles = $rolesQuery->get()
                ->flatMap(fn ($role) => $role->permissions->pluck('name'));

            $direct = $user->directPermissions()->pluck('name');

            return $viaRoles->merge($direct)->unique()->values()->all();
        };

        try {
            return $ttl === null
                ? Cache::rememberForever($key, $loader)
                : Cache::remember($key, now()->addMinutes($ttl), $loader);
        } catch (\Throwable) {
            // Cache unavailable (e.g. Redis not running) — fall back to direct DB query.
            return $loader();
        }
    }

    /**
     * Bust the permission cache for a specific user.
     * Call after assigning/revoking roles or direct permissions.
     */
    public function flushUser(Authenticatable|string $user): void
    {
        $id = $user instanceof Authenticatable
            ? (string) $user->getAuthIdentifier()
            : $user;

        try {
            Cache::forget($this->cacheKey($id));
        } catch (\Throwable) {
            // Cache unavailable — ignore, no stale data to bust.
        }
    }

    /**
     * Bust the permission cache for all users assigned to a role.
     * Call after changing a role's permission set.
     */
    public function flushRole(Role|string $role): void
    {
        $roleId = $role instanceof Role ? $role->id : $role;

        DB::table('model_roles')
            ->where('role_id', $roleId)
            ->pluck('model_id')
            ->each(function ($userId) {
                try {
                    Cache::forget($this->cacheKey((string) $userId));
                } catch (\Throwable) {
                    // Cache unavailable — ignore.
                }
            });
    }

    /**
     * Check if a user has a named permission (cache-aware).
     * Respects optional hierarchy from config('innertia.permissions_hierarchy').
     */
    public function check(Authenticatable $user, string|\BackedEnum $permission): bool
    {
        if (! method_exists($user, 'hasPermission')) {
            return false;
        }

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        $name = $permission instanceof \BackedEnum ? $permission->value : $permission;

        $all = $this->getUserPermissions($user);

        if (in_array($name, $all, true)) {
            return true;
        }

        return $this->checkHierarchy($all, $name);
    }

    public function isSuperAdmin(Authenticatable $user): bool
    {
        $roleName = config('innertia.super_admin_role', 'super_admin');

        if (! $roleName || ! method_exists($user, 'roles')) {
            return false;
        }

        return $user->roles()->where('name', $roleName)->exists();
    }

    // ── Sync ──────────────────────────────────────────────────────────────────

    /**
     * Sync named permissions from config to the database.
     *
     * NOT required for the app to work — run during deploys to keep descriptions
     * in DB up to date. Returns ['created', 'updated', 'skipped', 'deleted'].
     */
    public function sync(bool $prune = false): array
    {
        $definitions = $this->definitions();
        $isSaas      = \Innertia\Facades\Innertia::tenancyEnabled();
        $tenantId    = $isSaas && \Innertia\Facades\Innertia::tenant()
            ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
            : null;

        $created = $updated = $skipped = 0;

        foreach ($definitions as $name => $description) {
            $query = Permission::where('name', $name);

            if ($isSaas) {
                $query->where('tenant_id', $tenantId);
            }

            $existing = $query->first();

            if ($existing) {
                if ($existing->description !== $description) {
                    $existing->update(['description' => $description]);
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $attributes = ['name' => $name, 'description' => $description];

                if ($isSaas) {
                    $attributes['tenant_id'] = $tenantId;
                }

                Permission::create($attributes);
                $created++;
            }
        }

        $deleted = 0;

        if ($prune) {
            $query = Permission::whereNotIn('name', array_keys($definitions));

            if ($isSaas) {
                $query->where('tenant_id', $tenantId);
            }

            $deleted = $query->delete();
        }

        return compact('created', 'updated', 'skipped', 'deleted');
    }

    // ── Config readers ────────────────────────────────────────────────────────

    /** All permission groups in normalised format (flat, each group has app/app_label). */
    public function all(): array
    {
        return $this->normalised();
    }

    /**
     * All permissions grouped by app then by category.
     * Returns: [{ app, app_label, groups: [{ category, category_alias, permissions }] }]
     */
    public function allByApp(): array
    {
        $byApp = [];

        foreach ($this->normalised() as $group) {
            $app      = $group['app']       ?? 'default';
            $appLabel = $group['app_label'] ?? '';

            if (! isset($byApp[$app])) {
                $byApp[$app] = ['app' => $app, 'app_label' => $appLabel, 'groups' => []];
            }

            $byApp[$app]['groups'][] = [
                'category'       => $group['category'],
                'category_alias' => $group['category_alias'],
                'permissions'    => $group['permissions'],
            ];
        }

        return array_values($byApp);
    }

    /** Flat list of all configured permission names. */
    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    /** Category list only — without permission detail. */
    public function categories(): array
    {
        return array_map(
            fn ($g) => ['category' => $g['category'], 'category_alias' => $g['category_alias']],
            $this->normalised(),
        );
    }

    /** Hierarchy map from config('innertia.permissions_hierarchy'). */
    public function getHierarchy(): array
    {
        return config('innertia.permissions_hierarchy', []);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function cacheKey(string $userId): string
    {
        $tenantId = $this->currentTenantId();
        $base     = $tenantId
            ? "innertia.perms.{$tenantId}.{$userId}"
            : "innertia.perms.{$userId}";

        if (OrganizationsFeature::isActive()) {
            $ctx = \Innertia\Facades\Innertia::organization();
            $org = $ctx?->current();
            if ($org !== null) {
                // innertia.perms.{tenantId}.{orgId}.{userId}
                $parts = explode('.', $base);
                $userPart = array_pop($parts);
                $parts[]  = (string) $org;
                $parts[]  = $userPart;
                return implode('.', $parts);
            }
        }

        return $base;
    }

    private function currentTenantId(): ?string
    {
        return \Innertia\Facades\Innertia::tenant()
            ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
            : null;
    }

    private function checkHierarchy(array $userPermissions, string $needed): bool
    {
        foreach ($this->getHierarchy() as $grant => $implied) {
            if (in_array($needed, (array) $implied, true) && in_array($grant, $userPermissions, true)) {
                return true;
            }
        }

        return false;
    }

    private function definitions(): array
    {
        $map = [];

        foreach ($this->normalised() as $group) {
            foreach ($group['permissions'] as $name => $description) {
                $map[$name] = $description;
            }
        }

        return $map;
    }

    private function normalised(): array
    {
        $config = config('innertia.permissions', []);
        $groups = [];

        if ($this->isAppKeyed($config)) {
            // New format: ['backoffice' => ['label' => '...', 'permissions' => [...]]]
            foreach ($config as $appSlug => $appConfig) {
                $appLabel = $appConfig['label'] ?? \Illuminate\Support\Str::title($appSlug);
                foreach ($appConfig['permissions'] ?? [] as $entry) {
                    $group                = $this->expandEntry($entry);
                    $group['app']         = $appSlug;
                    $group['app_label']   = $appLabel;
                    $groups[]             = $group;
                }
            }
        } else {
            // Legacy flat format: [EnumClass::class, [...category array...]]
            foreach ($config as $entry) {
                $group                = $this->expandEntry($entry);
                $group['app']         = 'default';
                $group['app_label']   = '';
                $groups[]             = $group;
            }
        }

        return $groups;
    }

    private function isAppKeyed(array $config): bool
    {
        foreach ($config as $key => $value) {
            if (is_string($key) && is_array($value) && isset($value['permissions'])) {
                return true;
            }
        }
        return false;
    }

    private function expandEntry(mixed $entry): array
    {
        if (is_string($entry) && enum_exists($entry)) {
            return $this->expandEnum($entry);
        }
        if (is_array($entry) && isset($entry['category'])) {
            return $entry;
        }
        return ['category' => 'unknown', 'category_alias' => 'Unknown', 'permissions' => []];
    }

    private function expandEnum(string $enumClass): array
    {
        $permissions = [];

        foreach ($enumClass::cases() as $case) {
            $permissions[$case->value] = method_exists($case, 'description')
                ? $case->description()
                : $case->name;
        }

        $shortName = class_basename($enumClass);
        $category  = \Illuminate\Support\Str::snake(preg_replace('/Permissions$/i', '', $shortName));

        $alias = method_exists($enumClass, 'label')
            ? $enumClass::label()
            : \Illuminate\Support\Str::title(str_replace('_', ' ', $category));

        return [
            'category'       => $category,
            'category_alias' => $alias,
            'permissions'    => $permissions,
        ];
    }
}
