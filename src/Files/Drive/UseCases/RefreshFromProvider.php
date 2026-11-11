<?php

namespace Innertia\Files\Drive\UseCases;

use Illuminate\Auth\GenericUser;
use Innertia\Files\Drive\DriveFileFetcher;
use Innertia\Files\Drive\DriveSourceResolver;
use Innertia\Files\Drive\Exceptions\DriveExportTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveFileTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveItemNotFoundException;
use Innertia\Files\Drive\Exceptions\DriveItemNotImportableException;
use Innertia\Files\Drive\Import\DriveDirectoryMapper;
use Innertia\Files\Drive\Import\DriveFileRefresher;
use Innertia\Files\Drive\Import\ImportErrors;
use Innertia\Files\Imports\Enums\FileImportStatus;
use Innertia\Files\Imports\Models\FileImport;
use Innertia\Files\Models\File;
use Innertia\Files\Support\FilesLog;
use Innertia\Platform\Contracts\UseCase;

/**
 * "Actualizar desde Drive" de un archivo (registro kind=refresh en file_imports).
 * Un problema esperable (origen borrado, descarga deshabilitada, demasiado grande, archivo no
 * importado o en la papelera) deja el registro en `failed` con un motivo claro sin relanzar;
 * un error inesperado guarda un texto genérico (ImportErrors), se loguea completo y se relanza.
 * Claim atómico: solo un worker lo corre.
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
        $claimed = FileImport::query()
            ->whereKey($this->importId)
            ->where('status', FileImportStatus::Pending->value)
            ->update(['status' => FileImportStatus::Running->value, 'started_at' => now(), 'trace_id' => traceId()]);

        $import = FileImport::findOrFail($this->importId);

        if ($claimed === 0) {
            return $import;
        }

        FilesLog::info('files.drive.refresh: inicio', ['import_id' => $import->id, 'file_id' => $import->file_id]);

        $file = $import->file_id ? File::withTrashed()->find($import->file_id) : null;

        if (! $file || $file->provider !== DriveDirectoryMapper::PROVIDER || ! $file->external_id) {
            $import->markFailed('El archivo no fue importado desde Google Drive.');

            return $import;
        }
        if ($file->trashed()) {
            $import->markFailed('El archivo está en la papelera.');

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
            FilesLog::info('files.drive.refresh: fin', ['import_id' => $import->id, 'file_id' => $file->id, 'result' => $result]);
        } catch (DriveItemNotFoundException) {
            $import->markFailed('El archivo ya no existe en Google Drive o se perdió el acceso.');
        } catch (DriveItemNotImportableException|DriveFileTooLargeException|DriveExportTooLargeException $e) {
            $import->markFailed(ImportErrors::publicMessage($e));
        } catch (\Throwable $e) {
            $import->markFailed(ImportErrors::publicMessage($e));
            FilesLog::warning('files.drive.refresh: falló', ['import_id' => $import->id, 'file_id' => $file->id, 'error' => $e->getMessage(), 'trace_id' => traceId()]);
            throw $e;
        }

        return $import;
    }

    /** El worker murió o agotó el timeout: no dejar el registro en `running` para siempre. */
    public function failed(?\Throwable $e = null): void
    {
        if ($e !== null) {
            FilesLog::warning('files.drive: el proceso se interrumpió', ['import_id' => $this->importId, 'error' => $e->getMessage()]);
        }

        // Al usuario solo mensajes públicos; el detalle queda en el registro.
        FileImport::find($this->importId)?->markFailedIfRunning('El proceso se interrumpió: ' . ($e ? ImportErrors::publicMessage($e) : 'sin detalle'));
    }
}
