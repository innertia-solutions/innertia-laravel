<?php

namespace Innertia\Roles\UseCases;

use Innertia\Exceptions\ConflictException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;
use Spatie\Permission\Models\Role;

class UpdateRole extends UseCase
{
    public function __construct(
        public readonly string $roleId,
        public readonly string $name,
    ) {}

    public function execute(): mixed
    {
        $role = Role::find($this->roleId);

        if (! $role) {
            throw new NotFoundException("Role \"{$this->roleId}\" not found.");
        }

        $exists = Role::where('name', $this->name)
            ->where('guard_name', $role->guard_name)
            ->where('id', '!=', $this->roleId)
            ->exists();

        if ($exists) {
            throw new ConflictException("The role name \"{$this->name}\" is already taken by another role.");
        }

        $role->update(['name' => $this->name]);

        return $role;
    }
}
