<?php

namespace Innertia\ApiKeys\Traits;

use Innertia\ApiKeys\Models\ApiKey;
use Innertia\Facades\Innertia;

/**
 * Add to your User model to manage personal API keys.
 *
 * Usage:
 *   $user->createApiKey('Mi integración', ['invoices.read'])
 *   $user->apiKeys()
 *   $user->revokeApiKey($id)
 *
 * Also available as static methods on Tenant (via service provider).
 */
trait HasApiKeys
{
    public function apiKeys()
    {
        return $this->hasMany(ApiKey::class, 'user_id');
    }

    /**
     * Create a user-scoped API key.
     * Returns ['key' => ApiKey, 'raw' => 'inn_u_xxx'] — raw shown once only.
     */
    public function createApiKey(
        string $name,
        array  $permissions = [],
        ?\Carbon\Carbon $expiresAt = null,
    ): array {
        $tenantId = $this->resolveTenantId();

        $this->validatePermissions('user', $permissions);

        $generated = ApiKey::generate(
            tenantId:    $tenantId,
            name:        $name,
            permissions: $permissions,
            userId:      (string) $this->getKey(),
            expiresAt:   $expiresAt,
        );

        $apiKey = ApiKey::create($generated['attributes']);

        return ['key' => $apiKey, 'raw' => $generated['raw']];
    }

    public function revokeApiKey(string $id): void
    {
        $this->apiKeys()->where('id', $id)->firstOrFail()->revoke();
    }

    private function resolveTenantId(): string
    {
        $tenant = Innertia::tenant();
        return $tenant ? (string) $tenant->getKey() : 'global';
    }

    private function validatePermissions(string $type, array $permissions): void
    {
        $available = config("innertia.api_keys.{$type}.available_permissions", []);
        $invalid   = array_diff($permissions, $available);

        if ($invalid) {
            throw new \InvalidArgumentException(
                "Invalid permissions for {$type} API key: " . implode(', ', $invalid)
            );
        }
    }
}
