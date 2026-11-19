<?php

namespace Innertia\ApiKeys;

use Illuminate\Support\Str;
use Innertia\ApiKeys\Models\ApiKey;

/**
 * Integration key format and lookup (public API).
 *
 *   pk_live_<40 random alphanumerics>   (pk_test_ when innertia.public_api.key_environment = 'test')
 *
 * Stored: `prefix` = first 16 chars (indexed, safe to display/log), `key` = SHA-256 hex of the raw key.
 * The raw key is returned once at creation/rotation and never stored nor logged.
 */
final class IntegrationKeys
{
    public const PREFIX_LENGTH = 16;

    /** @return array{raw: string, prefix: string, hash: string, hint: string} */
    public static function generate(): array
    {
        $env = config('innertia.public_api.key_environment', 'live') === 'test' ? 'test' : 'live';
        $raw = "pk_{$env}_" . Str::random(40);

        return [
            'raw'    => $raw,
            'prefix' => substr($raw, 0, self::PREFIX_LENGTH),
            'hash'   => self::hash($raw),
            'hint'   => '...' . substr($raw, -4),
        ];
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }

    public static function looksLikeIntegrationKey(string $raw): bool
    {
        return (bool) preg_match('/^pk_(live|test)_[A-Za-z0-9]{40}$/', $raw);
    }

    /** Active (not revoked/expired) integration key for the raw value, or null. Constant-time hash compare. */
    public static function find(string $raw): ?ApiKey
    {
        if (! self::looksLikeIntegrationKey($raw)) {
            return null;
        }

        $hash = self::hash($raw);

        $candidates = ApiKey::query()
            ->active()
            ->where('type', ApiKey::TYPE_INTEGRATION)
            ->where('prefix', substr($raw, 0, self::PREFIX_LENGTH))
            ->get();

        foreach ($candidates as $key) {
            if (hash_equals((string) $key->key, $hash)) {
                return $key;
            }
        }

        return null;
    }
}
