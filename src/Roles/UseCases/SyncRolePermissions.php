<?php

namespace Innertia\Roles\UseCases;

use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;
use Spatie\Permission\Models\Role;

class SyncRolePermissions extends UseCase
{
    public function __construct(
        public readonly string $roleId,
        public readonly array $permissions,
    ) {}

    public function execute(): mixed
    {
        $role = Role::find($this->roleId);

        if (! $role) {
            throw new NotFoundException("Role \"{$this->roleId}\" not found.");
        }

        $role->syncPermissions($this->permissions);

        return $role;
    }
}
