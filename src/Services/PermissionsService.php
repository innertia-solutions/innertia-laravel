<?php

namespace Innertia\Services;

use Innertia\Models\Permission;

/**
 * Manages named (app-level) permissions from config.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Permission definitions live in CODE, not in the DB.
 * The DB is populated lazily — no artisan command required before the app works.
 *
 * `sync` is an optional enrichment step: it creates missing named permissions
 * and updates their descriptions from the code definition to the DB.
 * Run it during deploys if you want the descriptions kept in sync for UIs.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Config formats supported (mix freely in the same array):
 *
 * 1. Classic array:
 *    'permissions' => [
 *        ['category' => 'users', 'category_alias' => 'Usuarios', 'permissions' => [
 *            'users.view'   => 'Ver lista de usuarios',
 *            'users.manage' => 'Crear, editar y eliminar usuarios',
 *        ]],
 *    ]
 *
 * 2. Enum class (recommended — type-safe, IDE-complete, carries descriptions):
 *    'permissions' => [
 *        \App\Enums\UserPermissions::class,
 *    ]
 *    Where the enum is a BackedEnum: string:
 *      enum UserPermissions: string
 *      {
 *          case View   = 'users.view';
 *          case Manage = 'users.manage';
 *
 *          // Optional — if present, sync() stores this in the DB description column.
 *          public function description(): string
 *          {
 *              return match($this) {
 *                  self::View   => 'Ver lista de usuarios',
 *                  self::Manage => 'Crear, editar y eliminar usuarios',
 *              };
 *          }
 *      }
 *
 * Optional permission hierarchy (config/innertia.php):
 *   'permissions_hierarchy' => [
 *       'users.manage' => ['users.view'],   // manage implies view
 *   ]
 */
class PermissionsService
{
    /**
     * Sync named permissions from config to the database.
     *
     * This is NOT required for the system to work — permissions are created
     * lazily on first use. Run this command during deploys to keep descriptions
     * in the DB up to date with the code definition.
     *
     * - Creates permissions that don't exist yet.
     * - Updates the description of existing ones from the code definition.
     * - Pass $prune = true to also delete permissions no longer in config.
     *
     * Returns ['created' => int, 'updated' => int, 'skipped' => int, 'deleted' => int]
     */
    public function sync(bool $prune = false): array
    {
        $definitions = $this->definitions(); // ['users.view' => 'Ver...', ...]
        $created     = 0;
        $updated     = 0;
        $skipped     = 0;

        $tenantId = (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;

        foreach ($definitions as $name => $description) {
            $existing = Permission::where('name', $name)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($existing) {
                if ($existing->description !== $description) {
                    $existing->update(['description' => $description]);
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                Permission::create([
                    'tenant_id'   => $tenantId,
                    'name'        => $name,
                    'description' => $description,
                ]);
                $created++;
            }
        }

        $deleted = 0;

        if ($prune) {
            $tenantId = (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;
            $deleted  = Permission::where('tenant_id', $tenantId)
                ->whereNotIn('name', array_keys($definitions))
                ->delete();
        }

        return compact('created', 'updated', 'skipped', 'deleted');
    }

    /**
     * Check if a user has a named permission.
     * Convenience wrapper around $user->hasPermission().
     *
     * Respects the optional hierarchy defined in config('innertia.permissions_hierarchy').
     */
    public function check(\Illuminate\Contracts\Auth\Authenticatable $user, string|\BackedEnum $permission): bool
    {
        if (! method_exists($user, 'hasPermission')) {
            return false;
        }

        $name = $permission instanceof \BackedEnum ? $permission->value : $permission;

        if ($user->hasPermission($name)) {
            return true;
        }

        return $this->checkHierarchy($user, $name);
    }

    /**
     * All permission groups as defined in config.
     * Useful for building role management UIs.
     *
     * [
     *   ['category' => 'users', 'category_alias' => 'Users', 'permissions' => [
     *       'users.view' => 'Ver lista de usuarios', ...
     *   ]],
     *   ...
     * ]
     */
    public function all(): array
    {
        return $this->normalised();
    }

    /**
     * Flat list of all configured permission names.
     */
    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * Category list only — without permission detail.
     */
    public function categories(): array
    {
        return array_map(
            fn ($group) => [
                'category'       => $group['category'],
                'category_alias' => $group['category_alias'],
            ],
            $this->normalised()
        );
    }

    /**
     * Hierarchy map from config.
     *
     * Example: ['users.manage' => ['users.view']]
     * Means: a user with 'users.manage' implicitly has 'users.view'.
     */
    public function getHierarchy(): array
    {
        return config('innertia.permissions_hierarchy', []);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    /**
     * Flat map of name → description for all configured named permissions.
     */
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
        $groups = [];

        foreach (config('innertia.permissions', []) as $entry) {
            if (is_string($entry) && enum_exists($entry)) {
                $groups[] = $this->expandEnum($entry);
            } elseif (is_array($entry) && isset($entry['category'])) {
                $groups[] = $entry;
            }
        }

        return $groups;
    }

    /**
     * Expand an enum class into the normalised group format.
     *
     * Reads descriptions from:
     *   1. $case->description() — if the method exists on the enum
     *   2. Falls back to the case name (e.g. 'View', 'Manage')
     */
    private function expandEnum(string $enumClass): array
    {
        $cases       = $enumClass::cases();
        $permissions = [];

        foreach ($cases as $case) {
            $description = method_exists($case, 'description')
                ? $case->description()
                : $case->name;

            $permissions[$case->value] = $description;
        }

        // Derive a readable category from the enum class name.
        // App\Enums\UserPermissions → 'user' → 'User'
        $shortName = class_basename($enumClass);
        $category  = strtolower(preg_replace('/Permissions$/i', '', $shortName));
        $category  = \Illuminate\Support\Str::snake($category);

        return [
            'category'       => $category,
            'category_alias' => \Illuminate\Support\Str::title(str_replace('_', ' ', $category)),
            'permissions'    => $permissions,
        ];
    }

    private function checkHierarchy(\Illuminate\Contracts\Auth\Authenticatable $user, string $needed): bool
    {
        foreach ($this->getHierarchy() as $grant => $implied) {
            if (in_array($needed, (array) $implied, true) && $user->hasPermission($grant)) {
                return true;
            }
        }

        return false;
    }
}
