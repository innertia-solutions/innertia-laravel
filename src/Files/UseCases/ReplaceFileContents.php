<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Events\FileVersionCreated;
use Innertia\Files\Exceptions\FileTrashedException;
use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\Support\Checksum;
use Innertia\Files\Support\FileVersioning;
use Innertia\Files\Support\FilesLog;
use Innertia\Files\Versioning\FileVersionResult;
use Symfony\Component\Mime\MimeTypes;

/**
 * ÚNICO camino para cambiar el contenido de un File.
 *
 * - Mismo checksum que el vigente → `unchanged`, no escribe bytes ni crea versión
 *   (solo persiste los metadatos externos de $meta, p. ej. la revisión de Drive).
 * - Si cambió: sube los bytes a una ruta NUEVA (nunca sobrescribe), y en una
 *   transacción con lockForUpdate archiva el vigente en file_versions y
 *   actualiza files (version + 1). Si la escritura o la BD fallan, borra los
 *   bytes nuevos.
 * - El evento FileVersionCreated y la poda corren con DB::afterCommit: si el
 *   llamador abrió una transacción, esperan a su commit (y no corren si hace
 *   rollback).
 *
 * Limitación conocida: si la transacción EXTERNA del llamador hace rollback, los
 * bytes nuevos ya escritos quedan huérfanos en el disk (basura inofensiva: ninguna
 * fila los referencia).
 *
 * $meta admite: restored_from_version, external_revision, external_export_mime,
 * external_synced_at, original_name. Cualquier otra clave se ignora.
 */
class ReplaceFileContents
{
    private const META_KEYS = ['restored_from_version', 'external_revision', 'external_export_mime', 'external_synced_at', 'original_name'];

    /** Metadatos que se persisten aunque el contenido no cambie. */
    private const EXTERNAL_META_KEYS = ['external_revision', 'external_export_mime', 'external_synced_at'];

    public function __construct(
        public readonly File $file,
        public readonly string $localPath,
        public readonly FileVersionSource $source,
        public readonly ?string $mimeType = null,
        public readonly ?string $extension = null,
        public readonly ?Authenticatable $performedBy = null,
        public readonly array $meta = [],
    ) {}

    public function execute(): FileVersionResult
    {
        FileVersioning::ensureReady();

        if (! is_file($this->localPath)) {
            throw new \InvalidArgumentException("No existe el archivo local {$this->localPath}.");
        }

        $current = File::withTrashed()->find($this->file->id)
            ?? throw new ModelNotFoundException("File {$this->file->id} no existe.");

        if ($current->trashed()) {
            throw new FileTrashedException($current->id);
        }

        $newChecksum = Checksum::ofLocal($this->localPath);
        $currentChecksum = $current->checksum ?? $this->lazyChecksum($current);

        if ($currentChecksum !== null && hash_equals($currentChecksum, $newChecksum)) {
            $this->applyExternalMeta($current);
            $this->syncCaller($current);

            return FileVersionResult::unchanged($current);
        }

        $disk = $current->disk;
        $extension = $this->normalizedExtension($current);
        $mimeType = $this->resolveMimeType($current, $extension);
        $size = $this->localSize();
        $newPath = 'files/' . now()->format('Y/m') . '/' . Str::uuid() . ($extension !== null ? ".{$extension}" : '');

        try {
            $this->writeBytes($disk, $newPath);

            $outcome = DB::transaction(function () use ($newPath, $newChecksum, $extension, $mimeType, $size, $current, $currentChecksum) {
                $locked = File::withTrashed()->whereKey($current->id)->lockForUpdate()->firstOrFail();

                if ($locked->trashed()) {
                    throw new FileTrashedException($locked->id);
                }

                // Otro proceso dejó ya estos mismos bytes como vigentes.
                if ($locked->checksum !== null && hash_equals($locked->checksum, $newChecksum)) {
                    $this->applyExternalMeta($locked);

                    return ['unchanged', $locked, null];
                }

                $previous = FileVersion::create([
                    'file_id'               => $locked->id,
                    'version'               => $locked->version,
                    'disk'                  => $locked->disk,
                    'path'                  => $locked->path,
                    'original_name'         => $locked->original_name,
                    'mime_type'             => $locked->mime_type,
                    'extension'             => $locked->extension,
                    'size'                  => $locked->size,
                    'checksum'              => $locked->checksum ?? ($locked->path === $current->path ? $currentChecksum : null),
                    'source'                => $locked->version_source ?? FileVersionSource::Upload->value,
                    'restored_from_version' => $locked->restored_from_version,
                    'external_revision'     => $locked->external_revision,
                    'external_export_mime'  => $locked->external_export_mime,
                    'created_by'            => $locked->version_created_by ?? $locked->created_by,
                    'created_at'            => $locked->version_created_at ?? $locked->created_at,
                    'superseded_at'         => now(),
                ]);

                $meta = array_intersect_key($this->meta, array_flip(self::META_KEYS));

                $locked->forceFill(array_merge([
                    'path'                  => $newPath,
                    'mime_type'             => $mimeType,
                    'extension'             => $extension,
                    'size'                  => $size,
                    'checksum'              => $newChecksum,
                    'version'               => $locked->version + 1,
                    'version_source'        => $this->source->value,
                    'version_created_by'    => $this->performedBy?->getAuthIdentifier(),
                    'version_created_at'    => now(),
                    'restored_from_version' => null,
                ], $meta))->save();

                return ['created', $locked, $previous];
            });
        } catch (\Throwable $e) {
            $this->deleteQuietly($disk, $newPath);
            throw $e;
        }

        [$status, $file, $previous] = $outcome;

        $this->syncCaller($file);

        if ($status === 'unchanged') {
            $this->deleteQuietly($disk, $newPath);

            return FileVersionResult::unchanged($file);
        }

        DB::afterCommit(function () use ($file, $previous) {
            event(new FileVersionCreated($file, $previous, $this->performedBy));

            try {
                (new PruneFileVersions($file))->execute();
            } catch (\Throwable $e) {
                FilesLog::warning('files.versioning: falló la poda de versiones', [
                    'file_id' => $file->id, 'error' => $e->getMessage(),
                ]);
            }
        });

        return FileVersionResult::created($file, $previous);
    }

    /** Archivo previo al feature: calcula y persiste su checksum. */
    private function lazyChecksum(File $current): ?string
    {
        try {
            $checksum = Checksum::ofStored($current->disk, $current->path);
        } catch (\Throwable $e) {
            // Sin checksum vigente no se puede detectar "sin cambios": se versiona igual.
            FilesLog::warning('files.versioning: no se pudo leer los bytes vigentes', [
                'file_id' => $current->id, 'disk' => $current->disk, 'path' => $current->path, 'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($checksum === null) {
            FilesLog::warning('files.versioning: bytes vigentes ausentes en storage', [
                'file_id' => $current->id, 'disk' => $current->disk, 'path' => $current->path,
            ]);

            return null;
        }

        // Condicional: nunca pisa una versión más nueva ni toca updated_at.
        File::withTrashed()
            ->whereKey($current->id)
            ->where('path', $current->path)
            ->whereNull('checksum')
            ->toBase()
            ->update(['checksum' => $checksum]);

        $current->setAttribute('checksum', $checksum);
        $current->syncOriginalAttribute('checksum');

        return $checksum;
    }

    /**
     * Contenido sin cambios: persiste solo los metadatos externos (revisión de
     * Drive, etc.) sobre la fila vigente, sin versión nueva ni updated_at.
     */
    private function applyExternalMeta(File $file): void
    {
        $external = array_intersect_key($this->meta, array_flip(self::EXTERNAL_META_KEYS));

        if ($external === []) {
            return;
        }

        foreach ($external as $key => $value) {
            $file->setAttribute($key, $value);
        }

        // Valores ya convertidos por los casts del modelo (fechas → string de BD).
        $values = array_intersect_key($file->getAttributes(), $external);

        File::withTrashed()
            ->whereKey($file->id)
            ->where('path', $file->path)
            ->toBase()
            ->update($values);

        $file->syncOriginalAttributes(array_keys($external));
    }

    private function normalizedExtension(File $current): ?string
    {
        $ext = strtolower((string) ($this->extension ?? $current->extension ?? ''));
        $ext = preg_replace('/[^a-z0-9]/', '', ltrim($ext, '.'));
        $ext = substr($ext, 0, 20);

        return $ext !== '' ? $ext : null;
    }

    private function resolveMimeType(File $current, ?string $extension): ?string
    {
        if ($this->mimeType !== null) {
            return $this->mimeType;
        }

        $currentExt = $current->extension !== null ? strtolower(ltrim($current->extension, '.')) : null;

        if ($extension === $currentExt && $current->mime_type !== null) {
            return $current->mime_type;
        }

        if ($extension !== null) {
            $guessed = MimeTypes::getDefault()->getMimeTypes($extension)[0] ?? null;
            if ($guessed !== null) {
                return $guessed;
            }
        }

        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($this->localPath);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return $current->mime_type;
    }

    private function localSize(): int
    {
        clearstatcache(true, $this->localPath);
        $size = filesize($this->localPath);

        if ($size === false) {
            throw new \RuntimeException("No se pudo leer el tamaño de {$this->localPath}.");
        }

        return $size;
    }

    private function writeBytes(string $disk, string $path): void
    {
        $stream = fopen($this->localPath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException("No se pudo abrir {$this->localPath}.");
        }

        try {
            $ok = Storage::disk($disk)->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($ok === false) {
            throw new \RuntimeException("No se pudo escribir la nueva versión en el disk {$disk}.");
        }
    }

    /** Limpieza que nunca enmascara el error original ni convierte un "unchanged" en error. */
    private function deleteQuietly(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (\Throwable $e) {
            FilesLog::warning('files.versioning: no se pudieron borrar bytes temporales', [
                'disk' => $disk, 'path' => $path, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** La instancia que recibió el UseCase queda igual a la BD. */
    private function syncCaller(File $fresh): void
    {
        $this->file->setRawAttributes($fresh->getAttributes(), true);
        $this->file->unsetRelation('versions');
    }
}
