<?php

namespace Innertia\Saas\Settings;

use Innertia\Settings\Models\Setting;
use Innertia\Settings\AppSettingsService;

class SaasSettingsService extends AppSettingsService
{
    protected function tenantId(): mixed
    {
        return tenant('id');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $tenantId = $this->tenantId();

        // No active tenant — behave like a plain app
        if ($tenantId === null) {
            return parent::get($key, $default);
        }

        $value = \Illuminate\Support\Facades\Cache::rememberForever(
            $this->cacheKey($key, $tenantId),
            fn() => Setting::where('tenant_id', $tenantId)->where('key', $key)->first()?->value
        );

        // Fallback to platform value
        if ($value === null) {
            return parent::get($key, $default);
        }

        return $value;
    }

    public function platform(): AppSettingsService
    {
        return new class extends AppSettingsService {
            protected function tenantId(): mixed { return null; }
        };
    }

    public function tenant(?string $tenantId = null): AppSettingsService
    {
        $id = $tenantId ?? $this->tenantId();

        return new class($id) extends AppSettingsService {
            public function __construct(private readonly mixed $id) {}
            protected function tenantId(): mixed { return $this->id; }
        };
    }
}
