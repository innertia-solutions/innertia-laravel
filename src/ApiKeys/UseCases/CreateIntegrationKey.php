<?php

namespace Innertia\ApiKeys\UseCases;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Innertia\ApiKeys\ApiScopes;
use Innertia\ApiKeys\IntegrationKeys;
use Innertia\ApiKeys\Models\ApiKey;
use Innertia\Facades\Innertia;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Webhooks\Support\WebhookTelemetry;

/**
 * Creates an integration key in the active tenant (space). The raw key is returned once.
 *
 *   ['key' => ApiKey, 'secret' => 'pk_live_…'] = (new CreateIntegrationKey('ERP', ['calls:read']))->execute();
 */
class CreateIntegrationKey extends UseCase
{
    public function __construct(
        public readonly string $name,
        public readonly array $scopes,
        public readonly ?string $organizationId = null,
        public readonly ?Carbon $expiresAt = null,
        public readonly ?string $createdBy = null,
    ) {}

    /** @return array{key: ApiKey, secret: string} */
    public function execute(): array
    {
        if ($this->scopes === []) {
            throw ValidationException::withMessages(['scopes' => 'Selecciona al menos un scope.']);
        }

        if ($unknown = ApiScopes::unknown($this->scopes)) {
            throw ValidationException::withMessages(['scopes' => 'Scopes desconocidos: ' . implode(', ', $unknown) . '.']);
        }

        $generated = IntegrationKeys::generate();

        $attributes = [
            'name'            => $this->name,
            'type'            => ApiKey::TYPE_INTEGRATION,
            'key'             => $generated['hash'],
            'key_hint'        => $generated['hint'],
            'prefix'          => $generated['prefix'],
            'permissions'     => array_values(array_unique($this->scopes)),
            'organization_id' => $this->organizationId,
            'expires_at'      => $this->expiresAt,
            'created_by'      => $this->createdBy ?? (auth()->check() ? (string) auth()->id() : null),
        ];

        if (Innertia::tenancyEnabled()) {
            $attributes['tenant_id'] = (string) Innertia::tenant()?->getKey();
        }

        $key = ApiKey::create($attributes);

        WebhookTelemetry::info('api_keys.created', ['module' => 'public_api', 'api_key_id' => $key->id, 'prefix' => $key->prefix]);

        return ['key' => $key, 'secret' => $generated['raw']];
    }
}
