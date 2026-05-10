<?php

namespace Innertia\Settings;

use Illuminate\Support\Facades\Cache;
use Innertia\Models\Setting;

class AppSettingsService
{
    protected function tenantId(): mixed
    {
        return null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $tenantId = $this->tenantId();
        $cacheKey = $this->cacheKey($key, $tenantId);

        $value = Cache::rememberForever($cacheKey, function () use ($key, $tenantId) {
            return Setting::where('tenant_id', $tenantId)
                ->where('key', $key)
                ->first()
                ?->value;
        });

        return $value !== null ? $value : $default;
    }

    public function getGroup(string $group): array
    {
        $tenantId = $this->tenantId();
        $prefix = rtrim($group, '.') . '.';

        return Setting::where('tenant_id', $tenantId)
            ->where('key', 'like', $prefix . '%')
            ->get()
            ->mapWithKeys(fn(Setting $s) => [substr($s->key, strlen($prefix)) => $s->value])
            ->all();
    }

    public function set(string $key, mixed $value, string $type = 'string', bool $encrypted = false): Setting
    {
        $tenantId = $this->tenantId();

        $setting = Setting::where('tenant_id', $tenantId)->where('key', $key)->firstOrNew([
            'tenant_id' => $tenantId,
            'key'       => $key,
        ]);

        $setting->value_type  = $type;
        $setting->is_encrypted = $encrypted;
        $setting->value       = $value;
        $setting->save();

        return $setting;
    }

    public function forget(string $key): bool
    {
        $tenantId = $this->tenantId();
        $setting  = Setting::where('tenant_id', $tenantId)->where('key', $key)->first();

        return $setting ? (bool) $setting->delete() : false;
    }

    protected function cacheKey(string $key, mixed $tenantId): string
    {
        return $tenantId === null
            ? "innertia_settings_platform_{$key}"
            : "innertia_settings_tenant_{$tenantId}_{$key}";
    }
}
