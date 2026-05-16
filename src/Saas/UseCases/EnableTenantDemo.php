<?php

namespace Innertia\Saas\UseCases;

use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;

class EnableTenantDemo extends UseCase
{
    public function __construct(
        public readonly string $tenantKey,
        public readonly string $email,
        public readonly string $password,
    ) {}

    public function execute(): mixed
    {
        $model  = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
        $tenant = $model::where('key', $this->tenantKey)->first();

        if (! $tenant) {
            throw new NotFoundException("Tenant \"{$this->tenantKey}\" not found.");
        }

        $tenant->configs = array_merge($tenant->configs ?? [], [
            'demo' => [
                'email'    => $this->email,
                'password' => $this->password,
            ],
        ]);

        $tenant->save();

        return $tenant;
    }
}
