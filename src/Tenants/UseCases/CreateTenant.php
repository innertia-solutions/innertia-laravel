<?php

namespace Innertia\Tenants\UseCases;

use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

class CreateTenant extends UseCase
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $status = 'trial',
        public readonly int $trialDays = 14,
    ) {}

    public function execute(): mixed
    {
        $model = config('innertia.saas.tenant_model', \Innertia\Models\Tenant::class);

        if ($model::where('key', $this->key)->exists()) {
            throw new ConflictException("A tenant with key \"{$this->key}\" already exists.");
        }

        $data = [
            'key'    => $this->key,
            'name'   => $this->name,
            'status' => $this->status,
        ];

        if ($this->status === 'trial') {
            $data['trial_ends_at'] = now()->addDays($this->trialDays);
        }

        return $model::create($data);
    }
}
