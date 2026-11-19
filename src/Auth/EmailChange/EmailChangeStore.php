<?php

namespace Innertia\Auth\EmailChange;

use Illuminate\Support\Facades\Cache;

/**
 * Pending email change per user (cache store: must be shared between instances, e.g. Redis).
 * {email, code_hash, attempts, expires_at}; TTL 15 min, 5 attempts.
 */
final class EmailChangeStore
{
    public const TTL_MINUTES = 15;
    public const MAX_ATTEMPTS = 5;

    public static function put(string $userId, string $email, string $code): void
    {
        Cache::put(self::key($userId), [
            'email'      => $email,
            'code_hash'  => hash_hmac('sha256', $code, (string) config('app.key')),
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->getTimestamp(),
        ], now()->addMinutes(self::TTL_MINUTES));
    }

    public static function get(string $userId): ?array
    {
        $pending = Cache::get(self::key($userId));

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return $pending;
    }

    public static function matches(array $pending, string $code): bool
    {
        return hash_equals((string) $pending['code_hash'], hash_hmac('sha256', $code, (string) config('app.key')));
    }

    public static function recordFailure(string $userId, array $pending): int
    {
        $pending['attempts'] = (int) $pending['attempts'] + 1;
        $ttl = max(1, (int) $pending['expires_at'] - now()->getTimestamp());
        Cache::put(self::key($userId), $pending, $ttl);

        return $pending['attempts'];
    }

    public static function forget(string $userId): void
    {
        Cache::forget(self::key($userId));
    }

    private static function key(string $userId): string
    {
        return 'innertia_email_change_' . $userId;
    }
}
