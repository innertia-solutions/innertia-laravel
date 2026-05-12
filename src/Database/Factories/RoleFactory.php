<?php

namespace Innertia\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spatie\Permission\Models\Role;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'name'       => $this->faker->unique()->word(),
            'guard_name' => 'api',
        ];
    }

    public function withPermissions(array $permissions): static
    {
        return $this->afterCreating(function (Role $role) use ($permissions) {
            $role->syncPermissions($permissions);
        });
    }
}
