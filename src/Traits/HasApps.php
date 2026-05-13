<?php

namespace Innertia\Traits;

use Innertia\Models\UserApp;

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
     */
    public function appKeys(): array
    {
        $configured = array_keys(config('innertia.apps', []));

        return UserApp::where('user_id', $this->getKey())
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentAppTenantId()))
            ->whereIn('app', $configured)
            ->pluck('app')
            ->all();
    }

    public function hasApp(string $key): bool
    {
        if (! array_key_exists($key, config('innertia.apps', []))) {
            return false;
        }

        return UserApp::where('user_id', $this->getKey())
            ->where('app', $key)
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentAppTenantId()))
            ->exists();
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
    }

    public function revokeApp(string $key): void
    {
        UserApp::where('user_id', $this->getKey())
            ->where('app', $key)
            ->when(config('innertia.mode') === 'saas', fn ($q) => $q->where('tenant_id', $this->currentAppTenantId()))
            ->delete();
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
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function currentAppTenantId(): ?string
    {
        return (function_exists('tenant') && tenant()) ? (string) tenant('id') : null;
    }
}
