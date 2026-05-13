<?php

namespace Innertia\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Innertia\Auth\RBAC\Models\Role;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name'        => $this->faker->unique()->word(),
            'description' => null,
            'tenant_id'   => null,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function withPermissions(array $permissions): static
    {
        return $this->afterCreating(function (Role $role) use ($permissions) {
            $role->syncPermissions($permissions);
        });
    }
}
