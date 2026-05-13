<?php

namespace Innertia\Services;

use Innertia\Models\Permission;

/**
 * Manages named (app-level) permissions from config.
 *
 * Config formats supported:
 *
 * 1. Classic array format:
 *    'permissions' => [
 *        ['category' => 'users', 'category_alias' => 'Usuarios', 'permissions' => [
 *            'users.view'   => 'Ver usuarios',
 *            'users.manage' => 'Gestionar usuarios',
 *        ]],
 *    ]
 *
 * 2. Enum class format (recommended — type-safe, IDE-complete):
 *    'permissions' => [
 *        \App\Enums\UserPermissions::class,
 *        \App\Enums\ClientPermissions::class,
 *    ]
 *    Where each enum is a BackedEnum: string:
 *      enum UserPermissions: string {
 *          case View   = 'users.view';
 *          case Manage = 'users.manage';
 *      }
 *
 * Both formats can be mixed in the same config array.
 *
 * Optional permission hierarchy (declare in config/innertia.php):
 *   'permissions_hierarchy' => [
 *       'users.manage' => ['users.view'],   // manage implies view
 *   ]
 *
 * The hierarchy is intentionally NOT built into the DB schema — it is a
 * domain concern that varies per project. Enable it per-app via config.
 */
class PermissionsService
{
    /**
     * Sync named permissions from config to the database.
     *
     * Creates permissions that do not exist yet.
     * Pass $prune = true to also delete permissions no longer in config.
     *
     * Returns ['created' => int, 'skipped' => int, 'deleted' => int]
     */
    public function sync(bool $prune = false): array
    {
        $configured = $this->keys();
        $created    = 0;
        $skipped    = 0;

        foreach ($configured as $name) {
            $exists = Permission::whereNull('entity_type')->where('name', $name)->exists();

            if ($exists) {
                $skipped++;
            } else {
                Permission::findOrCreate($name);
                $created++;
            }
        }

        $deleted = 0;

        if ($prune) {
            $deleted = Permission::named()
                ->whereNotIn('name', $configured)
                ->delete();
        }

        return compact('created', 'skipped', 'deleted');
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

        // Optional hierarchy check
        return $this->checkHierarchy($user, $name);
    }

    /**
     * All permission groups as defined in config.
     * Useful for building role management UIs.
     *
     * Returns a normalised array — enum entries are expanded to the same
     * structure as classic array entries.
     *
     * [
     *   ['category' => 'users', 'category_alias' => 'Users', 'permissions' => [
     *       'users.view' => 'users.view', ...
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
        $keys = [];

        foreach ($this->normalised() as $group) {
            foreach (array_keys($group['permissions'] ?? []) as $name) {
                $keys[] = $name;
            }
        }

        return $keys;
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

    private function normalised(): array
    {
        $groups = [];

        foreach (config('innertia.permissions', []) as $entry) {
            if (is_string($entry) && enum_exists($entry)) {
                // Enum class → expand to classic group format
                $groups[] = $this->expandEnum($entry);
            } elseif (is_array($entry) && isset($entry['category'])) {
                $groups[] = $entry;
            }
        }

        return $groups;
    }

    private function expandEnum(string $enumClass): array
    {
        $cases       = $enumClass::cases();
        $permissions = [];

        foreach ($cases as $case) {
            $permissions[$case->value] = $case->name; // value = 'users.view', name = 'View'
        }

        // Derive a readable category from the enum class name
        // e.g. App\Enums\UserPermissions → 'user_permissions' → 'user'
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
        $hierarchy = $this->getHierarchy();

        // hierarchy: ['users.manage' => ['users.view']]
        // If the user has 'users.manage', they implicitly have 'users.view'.
        foreach ($hierarchy as $grant => $implied) {
            if (in_array($needed, (array) $implied, true) && $user->hasPermission($grant)) {
                return true;
            }
        }

        return false;
    }
}
