<?php

namespace Innertia\PublicApi\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Detects whether the product already ran the public API migration (columns are additive, so code paths
 * degrade to the legacy behaviour until `migrate` runs). Cached per application instance.
 */
final class IntegrationsSchema
{
    /** @var \WeakMap<object, array<string, bool>>|null */
    private static ?\WeakMap $cache = null;

    public static function webhooksV2(): bool
    {
        return self::check('webhooks.v2', fn () => Schema::hasColumn('webhooks', 'signing_secret'));
    }

    public static function webhookLogsV2(): bool
    {
        return self::check('webhook_logs.v2', fn () => Schema::hasColumn('webhook_logs', 'message_id'));
    }

    public static function apiKeysV2(): bool
    {
        return self::check('api_keys.v2', fn () => Schema::hasColumn('api_keys', 'prefix'));
    }

    public static function requestLogs(): bool
    {
        return self::check('api_request_logs', fn () => Schema::hasTable('api_request_logs'));
    }

    public static function idempotency(): bool
    {
        return self::check('api_idempotency_keys', fn () => Schema::hasTable('api_idempotency_keys'));
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function check(string $key, callable $probe): bool
    {
        $app = app();
        self::$cache ??= new \WeakMap;
        $known = self::$cache[$app] ?? [];

        if (($known[$key] ?? false) === true) {
            return true;
        }

        try {
            $has = (bool) $probe();
        } catch (Throwable) {
            return false;
        }

        // Only positive results are cached (a later migrate in the same process is picked up).
        if ($has) {
            $known[$key] = true;
            self::$cache[$app] = $known;
        }

        return $has;
    }
}
