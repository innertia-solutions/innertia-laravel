<?php

namespace Innertia\Passkeys\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Innertia\Settings\AppSettingsService;
use Throwable;

/**
 * Interruptor de passkeys. Vive en BD (tabla `settings`, nivel plataforma, tenant_id NULL
 * aunque el producto sea saas/open) y se edita desde /platform. Sin env ni config.
 * Si la BD no responde o la tabla `settings` no existe, rigen los defaults (encendido).
 * Los booleanos resueltos se cachean con claves explícitas (se invalidan en save()); la
 * existencia de tablas solo cachea el `true`, así un `migrate` posterior las activa sin flush.
 */
final class PasskeySettings
{
    public const KEY_ENABLED = 'passkeys.enabled';
    public const KEY_ALLOW_SUBDOMAINS = 'passkeys.allow_subdomains';

    private const CACHE_ENABLED = 'innertia_passkeys_enabled';
    private const CACHE_ALLOW_SUBDOMAINS = 'innertia_passkeys_allow_subdomains';
    private const CACHE_TABLE_PASSKEYS = 'innertia_passkeys_table';
    private const CACHE_TABLE_SETTINGS = 'innertia_passkeys_settings_table';

    /** @var array<string, true> */
    private static array $tables = [];

    public const DEFAULT_ENABLED = true;
    public const DEFAULT_ALLOW_SUBDOMAINS = false;

    public static function enabled(): bool
    {
        return self::cached(self::CACHE_ENABLED, self::KEY_ENABLED, self::DEFAULT_ENABLED);
    }

    public static function allowSubdomains(): bool
    {
        return self::cached(self::CACHE_ALLOW_SUBDOMAINS, self::KEY_ALLOW_SUBDOMAINS, self::DEFAULT_ALLOW_SUBDOMAINS);
    }

    public static function all(): array
    {
        return [
            'enabled'          => self::enabled(),
            'allow_subdomains' => self::allowSubdomains(),
        ];
    }

    /** Guarda valores ya validados. El modelo Setting invalida la caché al guardar. */
    public static function save(array $values): void
    {
        $store = new AppSettingsService();

        if (array_key_exists('enabled', $values)) {
            $store->set(self::KEY_ENABLED, (bool) $values['enabled'], 'boolean');
        }
        if (array_key_exists('allow_subdomains', $values)) {
            $store->set(self::KEY_ALLOW_SUBDOMAINS, (bool) $values['allow_subdomains'], 'boolean');
        }

        Cache::forget(self::CACHE_ENABLED);
        Cache::forget(self::CACHE_ALLOW_SUBDOMAINS);
    }

    /** ¿Existe la tabla `passkeys`? Sin ella (producto sin migrar) el módulo queda apagado. */
    public static function passkeysTableExists(): bool
    {
        return self::tableExists('passkeys', self::CACHE_TABLE_PASSKEYS);
    }

    /** Olvida cachés propias (booleanos y existencia de tablas). Útil en tests. */
    public static function flushCache(): void
    {
        self::$tables = [];
        foreach ([self::CACHE_ENABLED, self::CACHE_ALLOW_SUBDOMAINS, self::CACHE_TABLE_PASSKEYS, self::CACHE_TABLE_SETTINGS] as $key) {
            Cache::forget($key);
        }
    }

    private static function tableExists(string $table, string $cacheKey): bool
    {
        if (isset(self::$tables[$cacheKey])) {
            return true;
        }

        try {
            $exists = (bool) Cache::rememberForever($cacheKey, fn () => Schema::hasTable($table) ?: null);
        } catch (Throwable) {
            return false;
        }

        // Solo se memoriza el true: el false se revalida (un migrate posterior lo enciende).
        if ($exists) {
            self::$tables[$cacheKey] = true;
        }

        return $exists;
    }

    private static function cached(string $cacheKey, string $key, bool $default): bool
    {
        // Sin tabla settings no se consulta (una query fallida envenena una transacción abierta
        // en Postgres) ni se cachea: rige el default.
        if (! self::tableExists('settings', self::CACHE_TABLE_SETTINGS)) {
            return $default;
        }

        try {
            return (bool) Cache::rememberForever($cacheKey, fn () => (bool) self::read($key, $default));
        } catch (Throwable) {
            return $default;
        }
    }

    private static function read(string $key, mixed $default): mixed
    {
        try {
            return (new AppSettingsService())->get($key, $default) ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }
}
