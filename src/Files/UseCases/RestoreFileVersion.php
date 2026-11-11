<?php

namespace Innertia\Files\UseCases;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Enums\FileVersionSource;
use Innertia\Files\Events\FileVersionRestored;
use Innertia\Files\Exceptions\FileVersionBytesMissingException;
use Innertia\Files\Exceptions\FileVersionNotFoundException;
use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\Support\FileVersioning;
use Innertia\Files\Versioning\FileVersionResult;

/**
 * Restaurar = crear una versión NUEVA con los bytes de una antigua (como Drive).
 * El historial nunca se edita. Se copia a un temporal local para que la versión
 * restaurada tenga sus propios bytes (la retención puede borrar la antigua).
 */
class RestoreFileVersion
{
    public function __construct(
        public readonly File $file,
        public readonly int $version,
        public readonly ?Authenticatable $performedBy = null,
    ) {}

    public function execute(): FileVersionResult
    {
        FileVersioning::ensureReady();

        $fresh = File::withTrashed()->find($this->file->id)
            ?? throw new ModelNotFoundException("File {$this->file->id} no existe.");

        if ($this->version === (int) $fresh->version) {
            return FileVersionResult::unchanged($fresh);
        }

        $old = FileVersion::where('file_id', $this->file->id)->where('version', $this->version)->first()
            ?? throw new FileVersionNotFoundException($this->file->id, $this->version);

        $tmp = $this->copyToTemp($old);

        try {
            $result = (new ReplaceFileContents(
                file:        $this->file,
                localPath:   $tmp,
                source:      FileVersionSource::Restore,
                mimeType:    $old->mime_type,
                extension:   $old->extension,
                performedBy: $this->performedBy,
                meta:        ['restored_from_version' => $old->version],
            ))->execute();
        } finally {
            @unlink($tmp);
        }

        if ($result->wasCreated()) {
            $file = $result->file;
            $restoredFrom = $old->version;

            DB::afterCommit(function () use ($file, $restoredFrom) {
                event(new FileVersionRestored($file, $restoredFrom, $this->performedBy));
            });
        }

        return $result;
    }

    private function copyToTemp(FileVersion $old): string
    {
        $fs = Storage::disk($old->disk);

        if (! $fs->exists($old->path)) {
            throw new FileVersionBytesMissingException($old->version);
        }

        $in = $fs->readStream($old->path);

        if (! is_resource($in)) {
            throw new FileVersionBytesMissingException($old->version);
        }

        $tmp = sys_get_temp_dir() . '/innertia-restore-' . bin2hex(random_bytes(8))
            . ($old->extension ? '.' . $old->extension : '');
        $out = @fopen($tmp, 'wb');

        if ($out === false) {
            fclose($in);

            throw new \RuntimeException("No se pudo crear el temporal {$tmp}.");
        }

        try {
            stream_copy_to_stream($in, $out);
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);
            throw $e;
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
        }

        fclose($out);

        return $tmp;
    }
}
