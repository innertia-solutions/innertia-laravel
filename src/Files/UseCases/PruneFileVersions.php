<?php

namespace Innertia\Files\UseCases;

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;
use Innertia\Files\Support\FilesLog;

/**
 * Retención: conserva las N versiones superadas más recientes
 * (config innertia.files.versions.keep; null o '' = todas). Borra primero la
 * fila y luego los bytes: un fallo al borrar bytes deja basura registrada en
 * Telemetry, nunca una fila apuntando a bytes inexistentes.
 */
class PruneFileVersions
{
    public function __construct(
        public readonly File $file,
        public readonly ?int $keep = null,
    ) {}

    /** @return int versiones eliminadas */
    public function execute(): int
    {
        $keep = $this->keep ?? $this->configuredKeep();

        if ($keep === null) {
            return 0;
        }

        $stale = FileVersion::where('file_id', $this->file->id)
            ->orderByDesc('version')
            ->get()
            ->slice($keep);

        foreach ($stale as $version) {
            $version->delete();

            try {
                Storage::disk($version->disk)->delete($version->path);
            } catch (\Throwable $e) {
                FilesLog::warning('files.versioning: no se pudieron borrar bytes de una versión podada', [
                    'file_id' => $version->file_id, 'version' => $version->version,
                    'path' => $version->path, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $stale->count();
    }

    private function configuredKeep(): ?int
    {
        $keep = config('innertia.files.versions.keep');

        return ($keep === null || $keep === '') ? null : max(0, (int) $keep);
    }
}
