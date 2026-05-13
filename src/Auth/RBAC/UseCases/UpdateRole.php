<?php

namespace Innertia\Auth\RBAC\UseCases;

use Innertia\Exceptions\ConflictException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Platform\Contracts\UseCase;

class UpdateRole extends UseCase
{
    public function __construct(
        public readonly string  $roleId,
        public readonly string  $name,
        public readonly ?string $description = null,
    ) {
       parent::__construct();
       
    }

    public function execute(): mixed
    {
        $role = Role::find($this->roleId);

        if (! $role) {
            throw new NotFoundException("Role \"{$this->roleId}\" not found.");
        }

        $exists = Role::where('name', $this->name)
            ->where('tenant_id', $role->tenant_id)
            ->where('id', '!=', $this->roleId)
            ->exists();

        if ($exists) {
            throw new ConflictException("The role name \"{$this->name}\" is already taken by another role.");
        }

        $role->update([
            'name'        => $this->name,
            'description' => $this->description ?? $role->description,
        ]);

        return $role;
    }
}
