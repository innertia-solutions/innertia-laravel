<?php

namespace Innertia\Auth\RBAC\UseCases;

use Innertia\Exceptions\ConflictException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Platform\Organizations\OrganizationsFeature;

class UpdateRole extends UseCase
{
    public function __construct(
        public readonly string  $roleId,
        public readonly string  $name,
        public readonly ?string $description = null,
    ) {
       
    }

    public function execute(): mixed
    {
        $role = Role::find($this->roleId);

        if (! $role) {
            throw new NotFoundException("Role \"{$this->roleId}\" not found.");
        }

        // Unicidad del nombre con el mismo scoping que el modelo:
        // tenant_id solo aplica en SaaS; organization_id solo si el feature está activo.
        // En modo 'app' la tabla roles no tiene columna tenant_id.
        $exists = Role::where('name', $this->name)
            ->where('id', '!=', $this->roleId)
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $role->tenant_id))
            ->when(OrganizationsFeature::isActive(), fn ($q) => $q->where('organization_id', $role->organization_id))
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
