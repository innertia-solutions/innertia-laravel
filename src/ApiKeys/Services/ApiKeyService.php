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
    ): array {
        $tenantId = $tenantId ?? $this->currentTenantId();

        $this->validatePermissions('tenant', $permissions);

        $generated = ApiKey::generate(
            tenantId:    $tenantId,
            name:        $name,
            permissions: $permissions,
            userId:      null,
            expiresAt:   $expiresAt,
        );

        $apiKey = ApiKey::create($generated['attributes']);

        return ['key' => $apiKey, 'raw' => $generated['raw']];
    }

    public function list(?string $tenantId = null): \Illuminate\Database\Eloquent\Collection
    {
        return ApiKey::forTenant($tenantId ?? $this->currentTenantId())
            ->active()
            ->orderByDesc('created_at')
            ->get();
    }

    public function revoke(string $id, ?string $tenantId = null): void
    {
        ApiKey::forTenant($tenantId ?? $this->currentTenantId())
            ->findOrFail($id)
            ->revoke();
    }

    public function availablePermissions(string $type = 'tenant'): array
    {
        return config("innertia.api_keys.{$type}.available_permissions", []);
    }

    private function currentTenantId(): string
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
