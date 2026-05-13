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
     * App keys the user has access to (filtered to those still in config).
     *
     * Result is cached per user (+ tenant in SaaS) using the same TTL as
     * the permissions cache (config('innertia.cache.ttl')).
     */
    public function appKeys(): array
    {
        $ttl = config('innertia.cache.ttl', 60);

        $loader = function () {
            $configured = array_keys(config('innertia.apps', []));

            return UserApp::where('user_id', $this->getKey())
                ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentAppTenantId()))
                ->whereIn('app', $configured)
                ->pluck('app')
                ->all();
        };

        return $ttl === null
            ? Cache::rememberForever($this->appCacheKey(), $loader)
            : Cache::remember($this->appCacheKey(), now()->addMinutes($ttl), $loader);
    }

    public function hasApp(string $key): bool
    {
        if (! array_key_exists($key, config('innertia.apps', []))) {
            return false;
        }

        return in_array($key, $this->appKeys(), true);
    }

    // ── Grant / revoke ────────────────────────────────────────────────────────

    public function grantApp(string|array $keys): void
    {
        foreach ((array) $keys as $key) {
            if (! array_key_exists($key, config('innertia.apps', []))) {
                continue;
            }

            $data = ['user_id' => (string) $this->getKey(), 'app' => $key];

            if (config('innertia.mode') === 'saas') {
                $data['tenant_id'] = $this->currentAppTenantId();
            }

            UserApp::firstOrCreate($data);
        }

        $this->flushAppCache();
    }

    public function revokeApp(string $key): void
    {
        UserApp::where('user_id', $this->getKey())
            ->where('app', $key)
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentAppTenantId()))
            ->delete();

        $this->flushAppCache();
    }

    /**
     * Replace the user's full app access set within the current context.
     */
    public function syncApps(array $keys): void
    {
        $query = UserApp::where('user_id', $this->getKey());

        if (config('innertia.mode') === 'saas') {
            $query->where('tenant_id', $this->currentAppTenantId());
        }

        $query->delete();

        $this->grantApp($keys);
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
        return (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;
    }
}
