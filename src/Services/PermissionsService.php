<?php

namespace Innertia\Services;

use Spatie\Permission\Models\Permission;

class PermissionsService
{
    /**
     * Sync permissions from config to the database.
     *
     * Creates permissions that don't exist yet.
     * Pass $prune = true to also delete permissions no longer in config.
     *
     * Returns ['created' => int, 'skipped' => int, 'deleted' => int]
     */
    public function sync(bool $prune = false): array
    {
        $configured = $this->configuredKeys();

        $created = 0;
        $skipped = 0;

        foreach ($configured as $name) {
            $exists = Permission::where('name', $name)->exists();

            if ($exists) {
                $skipped++;
            } else {
                Permission::create(['name' => $name, 'guard_name' => 'api']);
                $created++;
            }
        }

        $deleted = 0;

        if ($prune) {
            $deleted = Permission::whereNotIn('name', $configured)->delete();
        }

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return compact('created', 'skipped', 'deleted');
    }

    /**
     * All permissions grouped by category, as defined in config.
     * Useful for building a role management UI.
     *
     * Returns:
     * [
     *   ['category' => 'users', 'category_alias' => 'Usuarios', 'permissions' => ['users.view' => 'Ver...']],
     *   ...
     * ]
     */
    public function all(): array
    {
        return config('innertia.permissions', []);
    }

    /**
     * Flat list of all permission names from config.
     */
    public function keys(): array
    {
        return $this->configuredKeys();
    }

    /**
     * Category list only (without permissions detail).
     */
    public function categories(): array
    {
        return array_map(
            fn ($group) => [
                'category'       => $group['category'],
                'category_alias' => $group['category_alias'],
            ],
            config('innertia.permissions', [])
        );
    }

    private function configuredKeys(): array
    {
        $keys = [];

        foreach (config('innertia.permissions', []) as $group) {
            foreach (array_keys($group['permissions'] ?? []) as $name) {
                $keys[] = $name;
            }
        }

        return $keys;
    }
}
