<?php

namespace Innertia\Auth\RBAC\Traits;

use Illuminate\Support\Facades\Cache;
use Innertia\Auth\Models\UserApp;

/**
 * Add to your User model to manage app/context access.
 *
 * Apps are defined in config('innertia.apps'):
 *   'apps' => [
 *       'backoffice'  => 'Administración',
 *       'technicians' => 'Portal Técnicos',
 *       'sales'       => 'Portal Ventas',
 *   ]
 *
 * Usage:
 *   $user->hasApp('backoffice')           // bool
 *   $user->grantApp('backoffice')
 *   $user->grantApp(['backoffice', 'sales'])
 *   $user->revokeApp('backoffice')
 *   $user->syncApps(['backoffice', 'sales'])
 *   $user->appKeys()                      // ['backoffice', 'sales']
 */
trait HasApps
{
    // ── Query ─────────────────────────────────────────────────────────────────

    /**
     * App keys the user has access to.
     *
     * App access is orthogonal to organization context — a grant is valid across
     * all orgs. Use appKeysInOrganization() for the org-specific context map
     * returned by /auth/me.
     *
     * Result is cached per (user, tenant).
     */
    public function appKeys(): array
    {
        $ttl = config('innertia.cache.ttl', 60);

        $loader = function () {
            $configured = array_keys(config('innertia.apps', []));

            return UserApp::where('user_id', $this->getKey())
                ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentAppTenantId()))
                ->whereIn('app', $configured)
                ->distinct()
                ->pluck('app')
                ->all();
        };

        return $ttl === null
            ? Cache::rememberForever($this->appCacheKey(), $loader)
            : Cache::remember($this->appCacheKey(), now()->addMinutes($ttl), $loader);
    }

    /**
     * App keys del user para una org específica. Útil para construir el shape
     * `{ context: [orgs] }` que devuelve /auth/me.
     */
    public function appKeysInOrganization(?int $organizationId): array
    {
        $configured = array_keys(config('innertia.apps', []));

        return UserApp::where('user_id', $this->getKey())
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentAppTenantId()))
            ->when($organizationId === null, fn ($q) => $q->whereNull('organization_id'))
            ->when($organizationId !== null, fn ($q) => $q->where(function ($qq) use ($organizationId) {
                $qq->where('organization_id', $organizationId)->orWhereNull('organization_id');
            }))
            ->whereIn('app', $configured)
            ->distinct()
            ->pluck('app')
            ->all();
    }

    /**
     * Mapa { app => [organization_ids] } — qué orgs tiene accesibles el user
     * por cada app. Cuando orgs está activo. Sin orgs, retorna `{ app => [null] }`.
     */
    public function accessibleOrganizationsByApp(): array
    {
        $configured = array_keys(config('innertia.apps', []));

        $rows = UserApp::where('user_id', $this->getKey())
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentAppTenantId()))
            ->whereIn('app', $configured)
            ->get(['app', 'organization_id']);

        return $rows->groupBy('app')
            ->map(fn ($group) => $group->pluck('organization_id')->unique()->values()->all())
            ->all();
    }

    public function hasApp(string $key): bool
    {
        if (! array_key_exists($key, config('innertia.apps', []))) {
            return false;
        }

        return in_array($key, $this->appKeys(), true);
    }

    // ── Grant / revoke ────────────────────────────────────────────────────────

    /**
     * Grant app access. `$organizationId` opcional:
     *   - null + orgs OFF → tenant-wide
     *   - null + orgs ON  → válido en todas las orgs del tenant
     *   - valor + orgs ON → solo en esa org
     */
    public function grantApp(string|array $keys, ?int $organizationId = null): void
    {
        foreach ((array) $keys as $key) {
            if (! array_key_exists($key, config('innertia.apps', []))) {
                continue;
            }

            $data = ['user_id' => (string) $this->getKey(), 'app' => $key];

            if (config('innertia.mode') === 'saas') {
                $data['tenant_id'] = $this->currentAppTenantId();
            }

            if (\Innertia\Platform\Organizations\OrganizationsFeature::isActive()) {
                $data['organization_id'] = $organizationId;
            }

            UserApp::firstOrCreate($data);
        }

        $this->flushAppCache();
    }

    public function revokeApp(string $key, ?int $organizationId = null): void
    {
        UserApp::where('user_id', $this->getKey())
            ->where('app', $key)
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentAppTenantId()))
            ->when(\Innertia\Platform\Organizations\OrganizationsFeature::isActive(),
                fn ($q) => $q->where('organization_id', $organizationId))
            ->delete();

        $this->flushAppCache();
    }

    /**
     * Replace the user's full app access set within the current context.
     * Si `organizationId` se pasa con orgs activo, sincroniza solo esa org.
     */
    public function syncApps(array $keys, ?int $organizationId = null): void
    {
        $query = UserApp::where('user_id', $this->getKey());

        if (config('innertia.mode') === 'saas') {
            $query->where('tenant_id', $this->currentAppTenantId());
        }

        if (\Innertia\Platform\Organizations\OrganizationsFeature::isActive()) {
            $query->where('organization_id', $organizationId);
        }

        $query->delete();

        $this->grantApp($keys, $organizationId);
        // grantApp already flushes — no double flush needed
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function appCacheKey(): string
    {
        $userId   = (string) $this->getKey();
        $tenantId = $this->currentAppTenantId();

        return $tenantId
            ? "innertia.apps.{$tenantId}.{$userId}"
            : "innertia.apps.{$userId}";
    }

    private function flushAppCache(): void
    {
        Cache::forget($this->appCacheKey());
    }

    private function currentAppTenantId(): ?string
    {
        return \Innertia\Facades\Innertia::tenant()
            ? (string) \Innertia\Facades\Innertia::tenant()->getKey()
            : null;
    }
}
