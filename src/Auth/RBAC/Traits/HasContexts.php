<?php

namespace Innertia\Auth\RBAC\Traits;

use Illuminate\Support\Facades\Cache;
use Innertia\Auth\Models\UserContext;

/**
 * Add to your User model to manage context access.
 *
 * Contexts are defined in config('innertia.contexts'):
 *   'contexts' => [
 *       'backoffice'  => 'Administración',
 *       'technicians' => 'Portal Técnicos',
 *       'sales'       => 'Portal Ventas',
 *   ]
 *
 * Usage:
 *   $user->hasContext('backoffice')           // bool
 *   $user->grantContext('backoffice')
 *   $user->grantContext(['backoffice', 'sales'])
 *   $user->revokeContext('backoffice')
 *   $user->syncContexts(['backoffice', 'sales'])
 *   $user->contextKeys()                      // ['backoffice', 'sales']
 */
trait HasContexts
{
    // ── Query ─────────────────────────────────────────────────────────────────

    /**
     * Context keys the user has access to.
     *
     * Context access is orthogonal to organization context — a grant is valid
     * across all orgs. Use contextKeysInOrganization() for the org-specific
     * context map returned by /auth/me.
     *
     * Result is cached per (user, tenant).
     */
    public function contextKeys(): array
    {
        $ttl = config('innertia.cache.ttl', 60);

        $loader = function () {
            $configured = array_keys(config('innertia.contexts', []));

            return UserContext::where('user_id', $this->getKey())
                ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentContextTenantId()))
                ->whereIn('context', $configured)
                ->distinct()
                ->pluck('context')
                ->all();
        };

        return $ttl === null
            ? Cache::rememberForever($this->contextCacheKey(), $loader)
            : Cache::remember($this->contextCacheKey(), now()->addMinutes($ttl), $loader);
    }

    /**
     * Context keys del user para una org específica. Útil para construir el
     * shape `{ context: [orgs] }` que devuelve /auth/me.
     */
    public function contextKeysInOrganization(?int $organizationId): array
    {
        $configured = array_keys(config('innertia.contexts', []));

        return UserContext::where('user_id', $this->getKey())
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentContextTenantId()))
            ->when($organizationId === null, fn ($q) => $q->whereNull('organization_id'))
            ->when($organizationId !== null, fn ($q) => $q->where(function ($qq) use ($organizationId) {
                $qq->where('organization_id', $organizationId)->orWhereNull('organization_id');
            }))
            ->whereIn('context', $configured)
            ->distinct()
            ->pluck('context')
            ->all();
    }

    /**
     * Mapa { context => [organization_ids] } — qué orgs tiene accesibles el
     * user por cada context. Sin orgs, retorna `{ context => [null] }`.
     */
    public function accessibleOrganizationsByContext(): array
    {
        $configured = array_keys(config('innertia.contexts', []));

        $rows = UserContext::where('user_id', $this->getKey())
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentContextTenantId()))
            ->whereIn('context', $configured)
            ->get(['context', 'organization_id']);

        return $rows->groupBy('context')
            ->map(fn ($group) => $group->pluck('organization_id')->unique()->values()->all())
            ->all();
    }

    public function hasContext(string $key): bool
    {
        if (! array_key_exists($key, config('innertia.contexts', []))) {
            return false;
        }

        return in_array($key, $this->contextKeys(), true);
    }

    // ── Grant / revoke ────────────────────────────────────────────────────────

    /**
     * Grant context access. `$organizationId` opcional:
     *   - null + orgs OFF → tenant-wide
     *   - null + orgs ON  → válido en todas las orgs del tenant
     *   - valor + orgs ON → solo en esa org
     */
    public function grantContext(string|array $keys, ?int $organizationId = null): void
    {
        foreach ((array) $keys as $key) {
            if (! array_key_exists($key, config('innertia.contexts', []))) {
                continue;
            }

            $data = ['user_id' => (string) $this->getKey(), 'context' => $key];

            if (config('innertia.mode') === 'saas') {
                $data['tenant_id'] = $this->currentContextTenantId();
            }

            if (\Innertia\Platform\Organizations\OrganizationsFeature::isActive()) {
                $data['organization_id'] = $organizationId;
            }

            UserContext::firstOrCreate($data);
        }

        $this->flushContextCache();
    }

    public function revokeContext(string $key, ?int $organizationId = null): void
    {
        UserContext::where('user_id', $this->getKey())
            ->where('context', $key)
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentContextTenantId()))
            ->when(\Innertia\Platform\Organizations\OrganizationsFeature::isActive(),
                fn ($q) => $q->where('organization_id', $organizationId))
            ->delete();

        $this->flushContextCache();
    }

    /**
     * Replace the user's full context access set within the current scope.
     * Si `organizationId` se pasa con orgs activo, sincroniza solo esa org.
     */
    public function syncContexts(array $keys, ?int $organizationId = null): void
    {
        $query = UserContext::where('user_id', $this->getKey());

        if (config('innertia.mode') === 'saas') {
            $query->where('tenant_id', $this->currentContextTenantId());
        }

        if (\Innertia\Platform\Organizations\OrganizationsFeature::isActive()) {
            $query->where('organization_id', $organizationId);
        }

        $query->delete();

        $this->grantContext($keys, $organizationId);
        // grantContext already flushes — no double flush needed
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function contextCacheKey(): string
    {
        $userId   = (string) $this->getKey();
        $tenantId = $this->currentContextTenantId();

        return $tenantId
            ? "innertia.contexts.{$tenantId}.{$userId}"
            : "innertia.contexts.{$userId}";
    }

    private function flushContextCache(): void
    {
        Cache::forget($this->contextCacheKey());
    }

    private function currentContextTenantId(): ?string
    {
        return \Innertia\Facades\Innertia::tenant()
            ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
            : null;
    }
}
