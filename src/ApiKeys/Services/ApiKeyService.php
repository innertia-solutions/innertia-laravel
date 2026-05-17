<?php

namespace Innertia\ApiKeys\Services;

use Innertia\ApiKeys\Models\ApiKey;
use Innertia\Facades\Innertia;

/**
 * Tenant-level API key management (not tied to a specific user).
 *
 * Usage:
 *   app(ApiKeyService::class)->create('ERP Integration', ['invoices.read', 'products.read'])
 *   app(ApiKeyService::class)->list()
 *   app(ApiKeyService::class)->revoke($id)
 */
class ApiKeyService
{
    public function create(
        string $name,
        array  $permissions = [],
        ?string $tenantId = null,
        ?\Carbon\Carbon $expiresAt = null,
        ?string $createdByKeyId = null,
    ): array {
        $tenantId = $tenantId ?? $this->currentTenantId();

        $this->validatePermissions('tenant', $permissions);

        $generated = ApiKey::generate(
            name:        $name,
            permissions: $permissions,
            tenantId:    $tenantId,
            expiresAt:   $expiresAt,
        );

        $apiKey = ApiKey::create(array_merge(
            $generated['attributes'],
            $createdByKeyId ? ['created_by_key_id' => $createdByKeyId] : [],
        ));

        return ['key' => $apiKey, 'raw' => $generated['raw']];
    }

    public function list(?string $tenantId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = ApiKey::active()->orderByDesc('created_at');

        if (config('innertia.mode') === 'saas') {
            $query->forTenant($tenantId ?? $this->currentTenantId());
        }

        return $query->get();
    }

    public function revoke(string $id, ?string $tenantId = null): void
    {
        $query = ApiKey::query();

        if (config('innertia.mode') === 'saas') {
            $query->forTenant($tenantId ?? $this->currentTenantId());
        }

        $query->findOrFail($id)->revoke();
    }

    /**
     * Returns available permissions as [key => description].
     * Supports both flat arrays (['perm']) and associative (['perm' => 'desc']).
     */
    public function availablePermissions(string $type = 'tenant'): array
    {
        $raw = config("innertia.api_keys.{$type}.available_permissions", []);
        return $this->normalizePermissions($raw);
    }

    private function currentTenantId(): string
    {
        $tenant = Innertia::tenant();
        return $tenant ? (string) $tenant->getKey() : 'global';
    }

    private function validatePermissions(string $type, array $permissions): void
    {
        $available = array_keys($this->availablePermissions($type));
        $invalid   = array_diff($permissions, $available);

        if ($invalid) {
            throw new \InvalidArgumentException(
                "Invalid permissions for {$type} API key: " . implode(', ', $invalid)
            );
        }
    }

    private function normalizePermissions(array $raw): array
    {
        // If flat array (['perm.read', 'perm.write']), convert to ['perm.read' => '', ...]
        if (array_is_list($raw)) {
            return array_fill_keys($raw, '');
        }
        return $raw; // already ['perm.read' => 'description']
    }
}
