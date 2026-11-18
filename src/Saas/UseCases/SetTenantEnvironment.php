<?php

namespace Innertia\Saas\UseCases;

use Innertia\Platform\Contracts\UseCase;
use Innertia\Saas\Enums\TenantEnvironment;

class SetTenantEnvironment extends UseCase
{
    public function __construct(
        public readonly string            $tenantKey,
        public readonly TenantEnvironment $environment,
    ) {}

    public function execute(): mixed
    {
        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;
        // firstOrFail → ModelNotFoundException → 404 in HTTP.
        $tenant = $model::where('key', $this->tenantKey)->firstOrFail();

        $tenant->update(['environment' => $this->environment]);

        return $tenant;
    }
}
