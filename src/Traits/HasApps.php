<?php

namespace Innertia\Traits;

use Illuminate\Database\Eloquent\Collection;
use Innertia\Models\App;
use Innertia\Models\TenantApp;
use Innertia\Models\UserApp;

/**
 * Add to User model to manage app access.
 * Add to Tenant model to manage enabled apps.
 *
 * On User:
 *   $user->apps()                          // all apps the user has access to
 *   $user->hasApp('backoffice')            // in current tenant (saas) or globally (app)
 *   $user->grantApp('backoffice')
 *   $user->revokeApp('backoffice')
 *
 * On Tenant:
 *   $tenant->apps()                        // all apps enabled for this tenant
 *   $tenant->hasApp('backoffice')
 *   $tenant->enableApp('backoffice')
 *   $tenant->disableApp('backoffice')
 */
trait HasApps
{
    public function apps(): Collection
    {
        if ($this->isTenantModel()) {
            return App::whereHas('tenantApps', function ($q) {
                $q->where('tenant_id', $this->getKey())->where('active', true);
            })->get();
        }

        // User
        $query = UserApp::where('user_id', $this->getKey());

        if (config('innertia.mode') === 'saas') {
            $query->where('tenant_id', $this->resolveTenantId());
        }

        $appIds = $query->pluck('app_id');

        return App::whereIn('id', $appIds)->where('active', true)->get();
    }

    public function hasApp(string $appKey): bool
    {
        if ($this->isTenantModel()) {
            return TenantApp::where('tenant_id', $this->getKey())
                ->whereHas('app', fn ($q) => $q->where('key', $appKey)->where('active', true))
                ->where('active', true)
                ->exists();
        }

        // User
        $app = App::findByKey($appKey);

        if (! $app) {
            return false;
        }

        $query = UserApp::where('user_id', $this->getKey())
            ->where('app_id', $app->id);

        if (config('innertia.mode') === 'saas') {
            $query->where('tenant_id', $this->resolveTenantId());
        }

        return $query->exists();
    }

    public function grantApp(string $appKey): void
    {
        $app = App::findByKey($appKey);

        if (! $app) {
            return;
        }

        $data = ['user_id' => $this->getKey(), 'app_id' => $app->id];

        if (config('innertia.mode') === 'saas') {
            $data['tenant_id'] = $this->resolveTenantId();
        }

        UserApp::firstOrCreate($data);
    }

    public function revokeApp(string $appKey): void
    {
        $app = App::findByKey($appKey);

        if (! $app) {
            return;
        }

        $query = UserApp::where('user_id', $this->getKey())->where('app_id', $app->id);

        if (config('innertia.mode') === 'saas') {
            $query->where('tenant_id', $this->resolveTenantId());
        }

        $query->delete();
    }

    public function enableApp(string $appKey): void
    {
        $app = App::findByKey($appKey);

        if (! $app || ! $this->isTenantModel()) {
            return;
        }

        TenantApp::updateOrCreate(
            ['tenant_id' => $this->getKey(), 'app_id' => $app->id],
            ['active' => true],
        );
    }

    public function disableApp(string $appKey): void
    {
        $app = App::where('key', $appKey)->first();

        if (! $app || ! $this->isTenantModel()) {
            return;
        }

        TenantApp::where('tenant_id', $this->getKey())
            ->where('app_id', $app->id)
            ->update(['active' => false]);
    }

    private function isTenantModel(): bool
    {
        return $this instanceof \Innertia\Models\Tenant;
    }

    private function resolveTenantId(): mixed
    {
        return function_exists('tenant') ? tenant('id') : null;
    }
}
