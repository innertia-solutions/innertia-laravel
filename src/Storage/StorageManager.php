<?php

namespace Innertia\Storage;

use Illuminate\Database\QueryException;
use Innertia\Facades\Settings;

/**
 * Resuelve credenciales de discos desde la capa de Settings (integraciones) en vez de
 * env. Opt-in vía config('innertia.storage.from_settings'); si un disco no tiene todas
 * sus keys en Settings, se deja intacta su config env (no-breaking).
 */
class StorageManager
{
    public function hydrate(): void
    {
        $map = (array) config('innertia.storage.from_settings', []);
        foreach ($map as $disk => $fields) {
            $values = [];
            $complete = true;
            foreach ($fields as $settingsKey => $configKey) {
                try {
                    $val = Settings::get($settingsKey);
                } catch (QueryException) {
                    // BD/tabla settings no disponible (p. ej. durante migrate o
                    // boot pre-migración): tratar como sin settings → no-op.
                    $complete = false;
                    break;
                }
                if (! filled($val)) {
                    $complete = false;
                    break;
                }
                $values[$configKey] = $val;
            }
            if ($complete && $values) {
                config(['filesystems.disks.'.$disk => array_merge(
                    (array) config('filesystems.disks.'.$disk, []),
                    $values,
                )]);
            }
        }
    }

    public function configured(string $disk): bool
    {
        $cfg = (array) config('filesystems.disks.'.$disk, []);

        // Configurado si tiene bucket+key (por Settings recién hidratadas o por env).
        return filled($cfg['bucket'] ?? null) && filled($cfg['key'] ?? null);
    }

    public function activeDisk(): string
    {
        $cloud = config('innertia.storage.cloud_disk', 'r2');

        return $this->configured($cloud) ? $cloud : config('innertia.storage.fallback_disk', 'public');
    }
}
