<?php

namespace Innertia\Saas\UseCases;

use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;

class DisableTenantDemo extends UseCase
{
    public function __construct(
        public readonly string $tenantKey,
    ) {}

    public function execute(): mixed
    {
        $model  = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
        $tenant = $model::where('key', $this->tenantKey)->first();

        if (! $tenant) {
            throw new NotFoundException("Tenant \"{$this->tenantKey}\" not found.");
        }

        $configs = $tenant->configs ?? [];
        unset($configs['demo']);
        $tenant->configs = $configs;
        $tenant->save();

        return $tenant;
    }
}
