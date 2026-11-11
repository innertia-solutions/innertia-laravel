<?php

namespace Innertia\Files\Drive\UseCases;

use Illuminate\Auth\GenericUser;
use Innertia\Files\Drive\DriveFileFetcher;
use Innertia\Files\Drive\DriveSourceResolver;
use Innertia\Files\Drive\Exceptions\DriveItemNotFoundException;
use Innertia\Files\Drive\Exceptions\DriveItemNotImportableException;
use Innertia\Files\Drive\Import\DriveDirectoryMapper;
use Innertia\Files\Drive\Import\DriveFileRefresher;
use Innertia\Files\Imports\Enums\FileImportStatus;
use Innertia\Files\Imports\Models\FileImport;
use Innertia\Files\Models\File;
use Innertia\Files\Support\FilesLog;
use Innertia\Platform\Contracts\UseCase;

/**
 * "Actualizar desde Drive" de un archivo (registro kind=refresh en file_imports).
 * Un problema esperable (origen borrado, descarga deshabilitada, archivo no importado) deja el registro en `failed`
 * con un motivo claro sin relanzar; un error inesperado además se relanza.
 */
class RefreshFromProvider extends UseCase
{
    public int $tries = 1;

    public function __construct(public readonly string $importId)
    {
        $this->timeout = (int) config('innertia.files.drive.job_timeout', 3600);
    }

    public function execute(): FileImport
    {
        $import = FileImport::findOrFail($this->importId);

        if ($import->status !== FileImportStatus::Pending) {
            return $import;
        }

        $import->markRunning(traceId());

        $file = $import->file_id ? File::find($import->file_id) : null;

        if (! $file || $file->provider !== DriveDirectoryMapper::PROVIDER || ! $file->external_id) {
            $import->markFailed('El archivo no fue importado desde Google Drive.');

            return $import;
        }

        try {
            $source = DriveSourceResolver::resolve();
            $item = $source->metadata($file->external_id);
            $by = $import->created_by ? new GenericUser(['id' => $import->created_by]) : null;

            $result = (new DriveFileRefresher(new DriveFileFetcher($source)))
                ->refresh($file, $item, $by, $import->native_format);

            $import->found = 1;
            $import->record($result, ['external_id' => $item->id, 'name' => $file->original_name, 'path' => '', 'file_id' => $file->id]);
            $import->markDone();
        } catch (DriveItemNotFoundException $e) {
            $import->markFailed('El archivo ya no existe en Google Drive o se perdió el acceso.');
        } catch (DriveItemNotImportableException $e) {
            $import->markFailed($e->getMessage());
        } catch (\Throwable $e) {
            $import->markFailed($e->getMessage());
            FilesLog::warning('files.drive.refresh: falló', ['import_id' => $import->id, 'file_id' => $file->id, 'error' => $e->getMessage()]);
            throw $e;
        }

        return $import;
    }
}
