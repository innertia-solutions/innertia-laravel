<?php

namespace Innertia\Auth\RBAC\UseCases;

use Innertia\Exceptions\NotFoundException;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Platform\Contracts\UseCase;

class DeleteRole extends UseCase
{
    public function __construct(
        public readonly string $roleId,
    ) {
       parent::__construct();
       
    }

    public function execute(): void
    {
        $role = Role::find($this->roleId);

        if (! $role) {
            throw new NotFoundException("Role \"{$this->roleId}\" not found.");
        }

        $role->delete();
    }
}
