<?php

namespace Innertia\Api;

/**
 * Resolves `config('innertia.api.available_permissions')` into a flat
 * key→description map, supporting two formats:
 *
 *   1. Flat array:   ['perm.name' => 'Description', ...]
 *   2. Enum class:   \App\Enums\ApiPermissions::class
 *      enum ApiPermissions: string {
 *          case ChatCreate = 'chat.create';
 *          public function description(): string { return 'Iniciar conversaciones'; }
 *      }
 *
 * The resolved map is used by Olimpo when creating API keys, and by the
 * VerifyClientApiKey middleware to validate permission requirements.
 */
class ApiPermissions
{
    /**
     * Returns a flat ['permission.name' => 'Human description'] map.
     */
    public static function all(): array
    {
        $config = config('innertia.api.available_permissions', []);

        // Enum class string → resolve via cases()
        if (is_string($config) && enum_exists($config)) {
            return static::fromEnum($config);
        }

        // Plain array ['perm' => 'description']
        if (is_array($config)) {
            return $config;
        }

        return [];
    }

    /**
     * Returns only the permission keys (without descriptions).
     */
    public static function keys(): array
    {
        return array_keys(static::all());
    }

    /**
     * Returns true if the given permission key is registered.
     */
    public static function exists(string $permission): bool
    {
        return array_key_exists($permission, static::all());
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function fromEnum(string $enumClass): array
    {
        $map = [];

        foreach ($enumClass::cases() as $case) {
            $map[$case->value] = method_exists($case, 'description')
                ? $case->description()
                : $case->name;
        }

        return $map;
    }
}
