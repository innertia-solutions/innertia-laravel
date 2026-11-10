<?php

namespace Innertia\Backups\Support;

/**
 * Lectura de config('innertia.backups') con defaults en código.
 *
 * mergeConfigFrom es shallow: si un producto declara su propio bloque `backups`
 * en config/innertia.php, reemplaza ENTERO el de la lib. Por eso cada key se lee
 * con su default aquí y no se asume que el bloque de la lib esté presente.
 */
final class BackupSettings
{
    public const DEFAULT_TIMES = ['03:00', '11:00', '19:00'];
    public const DEFAULT_TIMEZONE = 'America/Santiago';
    public const DEFAULT_RETENTION_DAYS = 30;
    public const DEFAULT_KEEP = 21;

    public static function mode(): string
    {
        return (string) config('innertia.backups.mode', 'app');
    }

    public static function disk(): string
    {
        return (string) config('innertia.backups.disk', 'backups');
    }

    public static function scheduleEnabled(): bool
    {
        return (bool) config('innertia.backups.schedule.enabled', true);
    }

    /** @return string[] */
    public static function times(): array
    {
        $times = config('innertia.backups.schedule.times', self::DEFAULT_TIMES);

        if (is_string($times)) {
            $times = explode(',', $times);
        }

        return array_values(array_filter(array_map('trim', (array) $times)));
    }

    public static function timezone(): string
    {
        return (string) (config('innertia.backups.schedule.timezone') ?: self::DEFAULT_TIMEZONE);
    }

    public static function retentionDays(): int
    {
        return (int) config('innertia.backups.retention_days', self::DEFAULT_RETENTION_DAYS);
    }

    public static function keep(): int
    {
        return (int) config('innertia.backups.keep', self::DEFAULT_KEEP);
    }
}
