<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
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

/**
 * ÚNICO camino para cambiar el contenido de un File.
 *
 * - Mismo checksum que el vigente → `unchanged`, no escribe nada.
 * - Si cambió: sube los bytes a una ruta NUEVA (nunca sobrescribe), y en una
 *   transacción con lockForUpdate archiva el vigente en file_versions y
 *   actualiza files (version + 1). Si la BD falla, borra los bytes nuevos.
 *
 * $meta admite: restored_from_version, external_revision, external_export_mime,
 * external_synced_at, original_name.
 */
class ReplaceFileContents
{
    private const META_KEYS = ['restored_from_version', 'external_revision', 'external_export_mime', 'external_synced_at', 'original_name'];

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
            ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException("File {$this->file->id} no existe.");

        if ($current->trashed()) {
            throw new FileTrashedException($current->id);
        }

        $newChecksum = Checksum::ofLocal($this->localPath);
        $currentChecksum = $current->checksum ?? $this->lazyChecksum($current);

        if ($currentChecksum !== null && hash_equals($currentChecksum, $newChecksum)) {
            $this->syncCaller($current);

            return FileVersionResult::unchanged($current);
        }

        $disk = $current->disk;
        $extension = strtolower((string) ($this->extension ?? $current->extension ?? ''));
        $newPath = 'files/' . now()->format('Y/m') . '/' . Str::uuid() . ($extension !== '' ? ".{$extension}" : '');

        $this->writeBytes($disk, $newPath);

        try {
            $outcome = DB::transaction(function () use ($newPath, $newChecksum, $extension, $current, $currentChecksum) {
                $locked = File::withTrashed()->whereKey($current->id)->lockForUpdate()->firstOrFail();

                if ($locked->trashed()) {
                    throw new FileTrashedException($locked->id);
                }

                // Otro proceso dejó ya estos mismos bytes como vigentes.
                if ($locked->checksum !== null && hash_equals($locked->checksum, $newChecksum)) {
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
                    'mime_type'             => $this->mimeType ?? (mime_content_type($this->localPath) ?: $locked->mime_type),
                    'extension'             => $extension !== '' ? $extension : null,
                    'size'                  => filesize($this->localPath),
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
            Storage::disk($disk)->delete($newPath);
            throw $e;
        }

        [$status, $file, $previous] = $outcome;

        if ($status === 'unchanged') {
            Storage::disk($disk)->delete($newPath);
            $this->syncCaller($file);

            return FileVersionResult::unchanged($file);
        }

        event(new FileVersionCreated($file, $previous, $this->performedBy));

        (new PruneFileVersions($file))->execute();

        $this->syncCaller($file);

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

        $current->forceFill(['checksum' => $checksum])->saveQuietly();

        return $checksum;
    }

    private function writeBytes(string $disk, string $path): void
    {
        $stream = fopen($this->localPath, 'rb');

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

    /** La instancia que recibió el UseCase queda igual a la BD. */
    private function syncCaller(File $fresh): void
    {
        $this->file->setRawAttributes($fresh->getAttributes(), true);
    }
}
