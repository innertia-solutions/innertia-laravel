<?php

namespace Innertia\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Innertia\Saas\Models\Tenant;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $company = $this->faker->company();

        return [
            'key'           => Str::slug($company),
            'name'          => $company,
            'status'        => 'active',
            'trial_ends_at' => null,
        ];
    }

    public function trial(int $days = 14): static
    {
        return $this->state(fn (array $attributes) => [
            'status'        => 'trial',
            'trial_ends_at' => now()->addDays($days),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
