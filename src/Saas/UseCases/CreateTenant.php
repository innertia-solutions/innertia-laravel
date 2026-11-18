<?php

namespace Innertia\Saas\UseCases;

use Innertia\Exceptions\ConflictException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Saas\Clients\ClientsFeature;
use Innertia\Saas\Enums\TenantEnvironment;
use Innertia\Saas\Models\Client;
use Innertia\Platform\Contracts\UseCase;

class CreateTenant extends UseCase
{
    public function __construct(
        public readonly string  $key,
        public readonly string  $name,
        public readonly string  $status = 'trial',
        public readonly int     $trialDays = 14,
        public readonly ?string $demoEmail = null,
        public readonly ?string $demoPassword = null,
        public readonly ?string            $clientId = null,
        public readonly ?TenantEnvironment $environment = null,
    ) {}

    public function execute(): mixed
    {
        $model = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);

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

        // Demo mode — pre-populate login credentials shown on the login page.
        if ($this->demoEmail && $this->demoPassword) {
            $data['configs'] = [
                'demo' => [
                    'email'    => $this->demoEmail,
                    'password' => $this->demoPassword,
                ],
            ];
        }

        if (ClientsFeature::isActive()) {
            $data['client_id']   = $this->resolveClientId();
            $data['environment'] = ($this->environment ?? TenantEnvironment::Production)->value;
        }

        $tenant = $model::create($data);

        // Se dispara para TODOS los paths de creación (open, consola, provisioning)
        // así cualquier tenant nuevo obtiene su suscripción/trial vía listeners.
        // CreateTenant no tiene owner user id → null (el listener solo usa el tenant).
        event(new \Innertia\Saas\Events\TenantCreated($tenant, null));

        return $tenant;
    }

    /** Explicit client, or an implicit one named after the tenant (self-serve and legacy paths). */
    private function resolveClientId(): string
    {
        if ($this->clientId !== null) {
            return Client::whereKey($this->clientId)->value('id')
                ?? throw new NotFoundException("Client \"{$this->clientId}\" not found.");
        }

        return (new CreateClient(name: $this->name, key: CreateClient::uniqueKey($this->key)))->execute()->id;
    }
}
