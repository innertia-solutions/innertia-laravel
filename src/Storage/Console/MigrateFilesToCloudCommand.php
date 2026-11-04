<?php

namespace Innertia\Storage\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Models\File;
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

        $query = File::withTrashed()->whereIn('disk', $from)->orderBy('created_at');
        $total = (clone $query)->count();
        $this->info(($dry ? '[dry-run] ' : '')."{$total} archivo(s) en ".implode(', ', $from)." → {$target}");

        $moved = 0;
        $missing = 0;
        $failed = 0;

        foreach ($query->cursor() as $file) {
            $source = Storage::disk($file->disk);

            if (! $source->exists($file->path)) {
                $missing++;
                $this->warn("  sin contenido en {$file->disk}: {$file->id} ({$file->path})");
                continue;
            }

            if ($dry) {
                $moved++;
                continue;
            }

            try {
                $stream = $source->readStream($file->path);
                Storage::disk($target)->writeStream($file->path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }

                $oldDisk = $file->disk;
                $file->forceFill(['disk' => $target])->saveQuietly();

                if ($this->option('delete-source')) {
                    Storage::disk($oldDisk)->delete($file->path);
                }
                $moved++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("  error en {$file->id}: ".$e->getMessage());
            }
        }

        $this->info(($dry ? 'Se migrarían' : 'Migrados').": {$moved} · sin contenido: {$missing} · errores: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
