<?php

namespace Innertia\Roles\UseCases;

use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;
use Spatie\Permission\Models\Role;

class DeleteRole extends UseCase
{
    public function __construct(
        public readonly string $roleId,
    ) {}

    public function execute(): void
    {
        $role = Role::find($this->roleId);

        if (! $role) {
            throw new NotFoundException("Role \"{$this->roleId}\" not found.");
        }

        $role->delete();
    }
}
