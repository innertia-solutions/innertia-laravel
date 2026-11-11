<?php

namespace Innertia\Storage\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\Support\FileVersioning;
use Innertia\Storage\StorageManager;

/**
 * Copia al disco cloud los archivos (tabla files) que siguen en discos locales y
 * actualiza su `disk`. Idempotente: lo ya migrado no se vuelve a tocar. Por defecto
 * conserva el original (usar --delete-source cuando esté verificado).
 */
class MigrateFilesToCloudCommand extends Command
{
    protected $signature = 'innertia:storage:migrate-files
        {--from=local,public : Discos de origen, separados por coma}
        {--dry-run : Solo informa qué se migraría}
        {--delete-source : Borra el archivo del disco de origen tras copiarlo}';

    protected $description = 'Migra los archivos de discos locales al disco cloud configurado (R2/S3/GCS)';

    public function handle(StorageManager $storage): int
    {
        if (! $storage->cloudConfigured()) {
            $this->error('El disco cloud no está configurado (innertia.storage.cloud + integración de almacenamiento).');

            return self::FAILURE;
        }

        $target = $storage->cloudDisk();
        $from = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('from')))));
        $dry = (bool) $this->option('dry-run');

        $versioned = FileVersioning::ready();

        $filesQuery = File::withTrashed()->whereIn('disk', $from)->orderBy('created_at');
        $versionsQuery = $versioned ? FileVersion::query()->whereIn('disk', $from)->orderBy('created_at') : null;

        $totalFiles = (clone $filesQuery)->count();
        $totalVersions = $versionsQuery ? (clone $versionsQuery)->count() : 0;
        $this->info(($dry ? '[dry-run] ' : '').$totalFiles.' archivo(s)'
            .($versioned ? " y {$totalVersions} versión(es)" : '').' en '.implode(', ', $from)." → {$target}");

        [$moved, $missing, $failed, $skipped] = $this->migrateRows($filesQuery, $target, $dry);

        $this->info(($dry ? 'Se migrarían' : 'Migrados').": {$moved} · sin contenido: {$missing} · errores: {$failed}"
            ." · omitidos por cambio concurrente: {$skipped}");

        if ($versionsQuery) {
            [$vMoved, $vMissing, $vFailed, $vSkipped] = $this->migrateRows($versionsQuery, $target, $dry);
            $this->info("Versiones migradas: {$vMoved} · sin contenido: {$vMissing} · errores: {$vFailed}"
                ." · omitidos por cambio concurrente: {$vSkipped}");
            $failed += $vFailed;
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Migra filas con `disk`/`path` (File o FileVersion) al disco destino.
     * El borrado del origen ocurre solo después de actualizar la fila. La fila se
     * actualiza de forma condicional (mismo disk y path que se copió): si otro
     * proceso la cambió mientras tanto (p. ej. un reemplazo de contenido), se
     * omite y el origen no se borra.
     *
     * @return array{int, int, int, int} [migrados, sin contenido, errores, omitidos por cambio concurrente]
     */
    private function migrateRows(Builder $query, string $target, bool $dry): array
    {
        $moved = 0;
        $missing = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($query->cursor() as $row) {
            $source = Storage::disk($row->disk);

            if (! $source->exists($row->path)) {
                $missing++;
                $this->warn("  sin contenido en {$row->disk}: {$row->id} ({$row->path})");
                continue;
            }

            if ($dry) {
                $moved++;
                continue;
            }

            $stream = null;
            try {
                $stream = $source->readStream($row->path);
                if (Storage::disk($target)->writeStream($row->path, $stream) === false) {
                    throw new \RuntimeException("no se pudo escribir {$row->path} en {$target}");
                }

                $oldDisk = $row->disk;
                $affected = $row->getConnection()->table($row->getTable())
                    ->where($row->getKeyName(), $row->getKey())
                    ->where('disk', $oldDisk)
                    ->where('path', $row->path)
                    ->update(['disk' => $target]);

                if ($affected === 0) {
                    $skipped++;
                    $this->warn("  omitido (la fila cambió durante la copia): {$row->id}");
                    continue;
                }

                if ($this->option('delete-source')) {
                    Storage::disk($oldDisk)->delete($row->path);
                }
                $moved++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  error en {$row->id}: ".$e->getMessage());
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        return [$moved, $missing, $failed, $skipped];
    }
}
