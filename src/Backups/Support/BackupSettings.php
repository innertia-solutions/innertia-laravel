<?php

namespace Innertia\Backups\Support;

use Innertia\Settings\AppSettingsService;
use Throwable;

/**
 * Política de respaldos (horarios + retención).
 *
 * Vive en BD (tabla `settings`, nivel plataforma) y se edita desde /platform
 * (BackupsController::settings / updateSettings). No hay env ni config de archivo
 * para esto, y no existe switch de apagado: el respaldo automático siempre corre.
 * Si la BD no responde o no hay valor guardado, rigen los defaults de aquí.
 *
 * mode/disk sí son config de infraestructura (config('innertia.backups')).
 */
final class BackupSettings
{
    public const DEFAULT_TIMES = ['03:00', '11:00', '19:00'];
    public const DEFAULT_TIMEZONE = 'America/Santiago';
    public const DEFAULT_RETENTION_DAYS = 30;
    public const DEFAULT_KEEP = 21;

    public const KEY_TIMES = 'backups.schedule.times';
    public const KEY_TIMEZONE = 'backups.schedule.timezone';
    public const KEY_RETENTION_DAYS = 'backups.retention.days';
    public const KEY_KEEP = 'backups.retention.keep';

    public const MAX_TIMES = 24;

    public static function mode(): string
    {
        return (string) config('innertia.backups.mode', 'app');
    }

    public static function disk(): string
    {
        return (string) config('innertia.backups.disk', 'backups');
    }

    /** @return string[] HH:MM ordenados y sin duplicados */
    public static function times(): array
    {
        $times = self::normalizeTimes(self::read(self::KEY_TIMES, self::DEFAULT_TIMES));

        return $times ?: self::DEFAULT_TIMES;
    }

    public static function timezone(): string
    {
        $tz = (string) self::read(self::KEY_TIMEZONE, self::DEFAULT_TIMEZONE);

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : self::DEFAULT_TIMEZONE;
    }

    public static function retentionDays(): int
    {
        return max(0, (int) self::read(self::KEY_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS));
    }

    public static function keep(): int
    {
        return max(0, (int) self::read(self::KEY_KEEP, self::DEFAULT_KEEP));
    }

    /** Política vigente (lo que consume el panel de /platform). */
    public static function all(): array
    {
        return [
            'times'          => self::times(),
            'timezone'       => self::timezone(),
            'retention_days' => self::retentionDays(),
            'keep'           => self::keep(),
        ];
    }

    public static function defaults(): array
    {
        return [
            'times'          => self::DEFAULT_TIMES,
            'timezone'       => self::DEFAULT_TIMEZONE,
            'retention_days' => self::DEFAULT_RETENTION_DAYS,
            'keep'           => self::DEFAULT_KEEP,
        ];
    }

    /** Guarda la política (valores ya validados). */
    public static function save(array $values): void
    {
        $store = self::store();

        if (array_key_exists('times', $values)) {
            $store->set(self::KEY_TIMES, self::normalizeTimes($values['times']), 'json');
        }
        if (array_key_exists('timezone', $values)) {
            $store->set(self::KEY_TIMEZONE, (string) $values['timezone']);
        }
        if (array_key_exists('retention_days', $values)) {
            $store->set(self::KEY_RETENTION_DAYS, (int) $values['retention_days'], 'integer');
        }
        if (array_key_exists('keep', $values)) {
            $store->set(self::KEY_KEEP, (int) $values['keep'], 'integer');
        }
    }

    /** @return string[] */
    public static function normalizeTimes(mixed $times): array
    {
        if (is_string($times)) {
            $times = explode(',', $times);
        }

        $valid = array_filter(
            array_map(fn ($t) => trim((string) $t), (array) $times),
            fn ($t) => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t),
        );

        $valid = array_values(array_unique($valid));
        sort($valid);

        return array_slice($valid, 0, self::MAX_TIMES);
    }

    /**
     * Nivel plataforma siempre: la instancia base no tiene tenant (en saas/open
     * consulta tenant_id IS NULL), aunque el producto haya bindeado el servicio
     * tenant-scoped en el contenedor.
     */
    private static function store(): AppSettingsService
    {
        return new AppSettingsService();
    }

    private static function read(string $key, mixed $default): mixed
    {
        try {
            return self::store()->get($key, $default) ?? $default;
        } catch (Throwable) {
            // BD caída / tabla settings ausente: el schedule igual se registra con defaults.
            return $default;
        }
    }
}
