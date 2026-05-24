<?php

namespace Innertia\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Saas\Models\Tenant;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'tenant_id' => Tenant::factory(),
            'name'      => $name,
            'key'       => Str::slug($name) . '-' . $this->faker->unique()->lexify('????'),
            'active'    => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->state(fn () => [
            'tenant_id' => $tenant instanceof Tenant ? $tenant->id : $tenant,
        ]);
    }
}
