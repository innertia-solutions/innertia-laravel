<?php

namespace Innertia\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = [
        'tenant_id',
        'key',
        'value_type',
        'value',
        'is_encrypted',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    protected function value(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                if ($value === null) {
                    return null;
                }

                $raw = $attributes['is_encrypted'] ? Crypt::decryptString($value) : $value;

                return match ($attributes['value_type'] ?? 'string') {
                    'json'    => json_decode($raw, true),
                    'boolean' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                    'integer' => (int) $raw,
                    'float'   => (float) $raw,
                    default   => $raw,
                };
            },
            set: function ($value) {
                $type = $this->value_type ?? 'string';

                $raw = match ($type) {
                    'json'    => json_encode($value),
                    'boolean' => $value ? '1' : '0',
                    default   => (string) $value,
                };

                return $this->is_encrypted ? Crypt::encryptString($raw) : $raw;
            }
        );
    }

    protected static function booted(): void
    {
        $invalidate = function (self $setting) {
            $key = $setting->tenant_id === null
                ? "innertia_settings_platform_{$setting->key}"
                : "innertia_settings_tenant_{$setting->tenant_id}_{$setting->key}";

            Cache::forget($key);
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }
}
