<?php

namespace Innertia\Passkeys\Support;

use Innertia\Settings\AppSettingsService;
use Throwable;

/**
 * Interruptor de passkeys. Vive en BD (tabla `settings`, nivel plataforma, tenant_id NULL
 * aunque el producto sea saas/open) y se edita desde /platform. Sin env ni config.
 * Si la BD no responde o la tabla no existe, rigen los defaults (encendido).
 */
final class PasskeySettings
{
    public const KEY_ENABLED = 'passkeys.enabled';
    public const KEY_ALLOW_SUBDOMAINS = 'passkeys.allow_subdomains';

    public const DEFAULT_ENABLED = true;
    public const DEFAULT_ALLOW_SUBDOMAINS = false;

    public static function enabled(): bool
    {
        return (bool) self::read(self::KEY_ENABLED, self::DEFAULT_ENABLED);
    }

    public static function allowSubdomains(): bool
    {
        return (bool) self::read(self::KEY_ALLOW_SUBDOMAINS, self::DEFAULT_ALLOW_SUBDOMAINS);
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
