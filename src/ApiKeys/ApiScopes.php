<?php

namespace Innertia\ApiKeys;

/**
 * Scopes an integration API key (and a webhook subscription) can be granted.
 * Convention: `<resource>:read|write`, plus special ones (`people:pii`, `recordings:read`).
 *
 *   ApiScopes::register('calls:read', 'Consultar llamadas');
 *   ApiScopes::register('people:pii', 'Ver RUT y datos personales completos', group: 'people');
 *
 * Scopes from config('innertia.public_api.scopes') (['key' => 'description']) are merged in.
 */
final class ApiScopes
{
    /** @var array<string, array{key: string, description: string, group: string}> */
    private static array $scopes = [];

    public static function register(string $key, string $description = '', ?string $group = null): void
    {
        self::$scopes[$key] = ['key' => $key, 'description' => $description, 'group' => $group ?? self::groupOf($key)];
    }

    /** @return array<string, array{key: string, description: string, group: string}> */
    public static function all(): array
    {
        $all = self::$scopes;

        foreach ((array) config('innertia.public_api.scopes', []) as $key => $description) {
            if (is_int($key)) {
                [$key, $description] = [(string) $description, ''];
            }
            $all[$key] ??= ['key' => $key, 'description' => (string) $description, 'group' => self::groupOf($key)];
        }

        ksort($all);

        return $all;
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return string[] the given scopes that are not registered */
    public static function unknown(array $scopes): array
    {
        $known = self::keys();

        return array_values(array_filter($scopes, fn ($s) => ! in_array($s, $known, true)));
    }

    public static function groupOf(string $key): string
    {
        return str_contains($key, ':') ? strstr($key, ':', true) : $key;
    }

    public static function flush(): void
    {
        self::$scopes = [];
    }
}
