<?php

namespace Innertia\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Platform\Teams\Models\Team;
use Innertia\Saas\Models\Tenant;

class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'tenant_id'       => Tenant::factory(),
            'organization_id' => null,
            'parent_team_id'  => null,
            'name'            => $this->faker->unique()->company() . ' Team',
            'description'     => $this->faker->sentence(),
        ];
    }

    public function forTenant(Tenant|int|string $tenant): static
    {
        return $this->state(fn () => [
            'tenant_id' => $tenant instanceof Tenant ? $tenant->id : $tenant,
        ]);
    }

    public function forOrganization(Organization|int $organization): static
    {
        return $this->state(fn () => [
            'organization_id' => $organization instanceof Organization ? $organization->id : $organization,
        ]);
    }

    public function childOf(Team|string $parent): static
    {
        return $this->state(fn () => [
            'parent_team_id' => $parent instanceof Team ? $parent->id : $parent,
        ]);
    }
}
