<?php

namespace Innertia\Saas\UseCases;

use Innertia\Exceptions\NotFoundException;
use Innertia\Platform\Contracts\UseCase;

class UpdateTenant extends UseCase
{
    public function __construct(
        public readonly string $tenantKey,
        public readonly ?string $name = null,
        public readonly ?string $status = null,
    ) {}

    public function execute(): mixed
    {
        $model = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);

        $tenant = $model::where('key', $this->tenantKey)->first();

        if (! $tenant) {
            throw new NotFoundException("Tenant \"{$this->tenantKey}\" not found.");
        }

        $data = array_filter([
            'name'   => $this->name,
            'status' => $this->status,
        ], fn ($value) => $value !== null);

        $tenant->update($data);

        return $tenant;
    }
}
