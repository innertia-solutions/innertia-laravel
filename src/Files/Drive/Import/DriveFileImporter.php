<?php

namespace Innertia\Files\Drive\Import;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Drive\DriveFileFetcher;
use Innertia\Files\Drive\DriveItem;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Imports\Models\FileImport;
use Innertia\Files\Models\File;
use Innertia\Files\Support\Checksum;
use Innertia\Files\Support\FilesLog;
use Innertia\Files\Support\FileVersioning;

/**
 * Importa UN ítem de Drive a un directorio. Dueño: el del import si lo trae; si no, el del
 * directorio (raíz: sin dueño), igual que las carpetas (DriveDirectoryMapper::ownerFor).
 * Si ese mismo ítem ya fue importado en ese directorio por el mismo dueño (File no tiene
 * tenant: el dueño aísla), lo refresca (versión nueva o sin cambios) en vez de duplicarlo.
 * Un lock por ítem+directorio+dueño evita duplicados entre imports concurrentes; si no se obtiene a tiempo,
 * `LockTimeoutException` se propaga y el llamador anota el ítem como fallido.
 */
final class DriveFileImporter
{
    /** TTL del lock: al menos lo que puede durar el job (un lock vencido a mitad deja duplicar). */
    public static function lockSeconds(): int
    {
        return max(300, (int) config('innertia.files.drive.job_timeout', 3600));
    }

    /** Espera máxima por un lock tomado por otro proceso. */
    public static function lockWait(): int
    {
        return (int) config('innertia.files.drive.lock_wait', 60);
    }

    public static function lockKey(string $itemId, ?string $directoryId, ImportOwner $owner): string
    {
        return 'innertia.files.drive.import:' . $itemId . ':' . ($directoryId ?? 'root') . ':' . $owner->key();
    }

    public function __construct(
        private readonly DriveFileFetcher $fetcher,
        private readonly DriveFileRefresher $refresher,
    ) {}

    /** @return array{0: 'imported'|'updated'|'unchanged', 1: string} resultado e id del File */
    public function import(DriveItem $item, ?Directory $directory, FileImport $import): array
    {
        $owner = DriveDirectoryMapper::ownerFor($directory, $import);
        $key = self::lockKey($item->id, $directory?->id, $owner);

        return Cache::lock($key, self::lockSeconds())->block(self::lockWait(), function () use ($item, $directory, $import, $owner) {
            $existing = $owner->scope(File::query())
                ->where('provider', DriveDirectoryMapper::PROVIDER)
                ->where('external_id', $item->id)
                ->where('directory_id', $directory?->id)
                ->first();

            if ($existing) {
                $by = $import->created_by ? new GenericUser(['id' => $import->created_by]) : null;

                return [$this->refresher->refresh($existing, $item, $by, $import->native_format), $existing->id];
            }

            return ['imported', $this->create($item, $directory, $import, $owner)->id];
        });
    }

    private function create(DriveItem $item, ?Directory $directory, FileImport $import, ImportOwner $owner): File
    {
        $fetched = $this->fetcher->fetch($item, $import->native_format);

        try {
            $disk = config('innertia.files.drive.disk') ?: config('filesystems.default', 'local');
            $path = 'files/' . now()->format('Y/m') . '/' . Str::uuid() . ($fetched->extension ? ".{$fetched->extension}" : '');

            try {
                $this->write($disk, $path, $fetched->localPath);

                $attributes = array_merge($owner->attributes(), [
                    'disk'          => $disk,
                    'path'          => $path,
                    'original_name' => $fetched->name,
                    'mime_type'     => $fetched->mime,
                    'extension'     => $fetched->extension,
                    'size'          => filesize($fetched->localPath),
                    'visibility'    => $import->visibility,
                    'directory_id'  => $directory?->id,
                    'created_by'    => $import->created_by,
                    'provider'      => DriveDirectoryMapper::PROVIDER,
                    'link_mode'     => 'local',
                    'external_id'   => $item->id,
                    'external_url'  => $item->webUrl,
                ]);

                if (FileVersioning::ready()) {
                    $attributes += [
                        'checksum'             => Checksum::ofLocal($fetched->localPath),
                        'version_source'       => FileVersionSource::ProviderImport->value,
                        'external_revision'    => $item->revision(),
                        'external_export_mime' => $fetched->exportMime,
                        'external_synced_at'   => now(),
                    ];
                }

                return File::create($attributes);
            } catch (\Throwable $e) {
                $this->discard($disk, $path, $e);

                throw $e;
            }
        } finally {
            $fetched->delete();
        }
    }

    private function write(string $disk, string $path, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException("No se pudo leer el temporal {$localPath}.");
        }

        try {
            $ok = Storage::disk($disk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($ok === false) {
            throw new \RuntimeException("No se pudo escribir el archivo importado en el disk {$disk}.");
        }
    }

    /** Borra bytes huérfanos sin tapar el error original. */
    private function discard(string $disk, string $path, \Throwable $cause): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            FilesLog::warning('files.drive.import: no se pudieron borrar bytes huérfanos', [
                'disk'  => $disk,
                'path'  => $path,
                'cause' => $cause->getMessage(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
