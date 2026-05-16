<?php

namespace Innertia\Olimpo;

use Illuminate\Support\Facades\Cache;
use Innertia\Olimpo\Contracts\OlimpoHandler;
use Innertia\Olimpo\Metrics\SystemMetrics;

/**
 * Default Olimpo handler backed by the SDK's Tenant model.
 * Apps that use SaaS mode get all standard endpoints for free.
 * Override individual methods or bind a custom OlimpoHandler to replace.
 */
class DefaultOlimpoHandler implements OlimpoHandler
{
    protected function tenantModel(): string
    {
        return config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
    }

    protected function findTenant(string $key): mixed
    {
        $model = $this->tenantModel();
        $tenant = $model::where('key', $key)->first();

        if (! $tenant) {
            abort(404, "Tenant \"{$key}\" not found.");
        }

        return $tenant;
    }

    public function health(): array
    {
        return ['db' => 'ok'];
    }

    public function createTenant(array $data): array
    {
        $model = $this->tenantModel();

        $tenant = $model::create([
            'key'    => $data['key'],
            'name'   => $data['name'],
            'status' => $data['status'] ?? 'active',
        ]);

        return $tenant->toArray();
    }

    public function getTenant(string $externalId): array
    {
        return $this->findTenant($externalId)->toArray();
    }

    public function deleteTenant(string $externalId): array
    {
        $tenant = $this->findTenant($externalId);
        $tenant->delete();

        return ['deleted' => true];
    }

    public function suspendTenant(string $externalId): array
    {
        $tenant = $this->findTenant($externalId);
        $tenant->update(['status' => 'inactive']);

        return $tenant->fresh()->toArray();
    }

    public function reactivateTenant(string $externalId): array
    {
        $tenant = $this->findTenant($externalId);
        $tenant->update(['status' => 'active']);

        return $tenant->fresh()->toArray();
    }

    public function updateTrial(string $externalId, array $data): array
    {
        $tenant = $this->findTenant($externalId);
        $tenant->update([
            'status'        => 'trial',
            'trial_ends_at' => $data['trial_ends_at'] ?? null,
        ]);

        return $tenant->fresh()->toArray();
    }

    public function flushCache(string $externalId): array
    {
        Cache::tags(["tenant:{$externalId}"])->flush();

        return ['flushed' => true];
    }

    public function getTenantUsers(string $externalId): array
    {
        return [];
    }

    public function impersonate(string $externalId, string $userId): array
    {
        return ['error' => 'impersonate not implemented — override DefaultOlimpoHandler'];
    }

    public function getTenantBackups(string $externalId): array
    {
        return [];
    }

    public function createBackup(string $externalId): array
    {
        return ['error' => 'backup not implemented — override DefaultOlimpoHandler'];
    }
}
