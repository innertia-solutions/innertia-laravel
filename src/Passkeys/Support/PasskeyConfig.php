<?php

namespace Innertia\Passkeys\Support;

final class PasskeyConfig
{
    public static function enabled(): bool
    {
        // Sin la tabla `passkeys` (producto que aún no migró) el módulo queda apagado.
        return PasskeySettings::passkeysTableExists() && PasskeySettings::enabled();
    }

    public static function rpId(): string
    {
        return (string) (config('innertia.passkeys.rp_id') ?: parse_url(self::frontendUrl(), PHP_URL_HOST));
    }

    public static function rpName(): string
    {
        return (string) (config('innertia.passkeys.rp_name') ?: config('app.name', 'Innertia'));
    }

    /** @return list<string> */
    public static function origins(): array
    {
        $origins = array_values(array_filter((array) config('innertia.passkeys.origins', [])));
        if ($origins !== []) {
            return $origins;
        }

        $p = parse_url(self::frontendUrl());
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? 'localhost') . (isset($p['port']) ? ':' . $p['port'] : '');

        return [$origin];
    }

    public static function challengeTtl(): int
    {
        return (int) config('innertia.passkeys.challenge_ttl', 300);
    }

    public static function allowSubdomains(): bool
    {
        return PasskeySettings::allowSubdomains();
    }

    private static function frontendUrl(): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url', 'http://localhost')), '/');
    }
}
