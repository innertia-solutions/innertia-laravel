<?php

namespace Innertia\Files\Drive\UseCases;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Drive\DriveFileFetcher;
use Innertia\Files\Drive\DriveItem;
use Innertia\Files\Drive\DriveSourceResolver;
use Innertia\Files\Drive\Exceptions\DriveException;
use Innertia\Files\Drive\Exceptions\DriveExportTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveFileTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveItemNotImportableException;
use Innertia\Files\Drive\Import\DriveDirectoryMapper;
use Innertia\Files\Drive\Import\DriveFileImporter;
use Innertia\Files\Drive\Import\DriveFileRefresher;
use Innertia\Files\Drive\Import\DriveImportPlanner;
use Innertia\Files\Imports\Enums\FileImportStatus;
use Innertia\Files\Imports\Models\FileImport;
use Innertia\Files\Support\FilesLog;
use Innertia\Platform\Contracts\UseCase;

/**
 * Proceso en cola de importación desde Drive (registro en file_imports).
 * Recorre → recrea carpetas → importa/refresca cada archivo. Un ítem que falla se anota y
 * no detiene el resto; una carpeta que no se puede crear se anota como fallida y todo lo que
 * contiene queda `skipped`. Un error de autenticación o de conexión con Drive (ya reintentado)
 * corta el proceso. Un error fatal deja el import en `failed` y se relanza (queda como caso).
 * Idempotente: solo corre desde `pending` (claim atómico, un solo worker lo toma).
 *
 * Comportamiento conocido (documentado, no se corrige):
 * - El dedupe es por directorio: reimportar un archivo que se movió localmente a otra carpeta
 *   lo vuelve a crear en la carpeta original (queda duplicado).
 * - Una selección que se solapa (una carpeta y un archivo suyo seleccionado suelto) importa ese
 *   archivo dos veces: dentro de su carpeta y en el destino.
 */
class ImportFromDrive extends UseCase
{
    private const SAVE_EVERY = 25;
    private const BLOCKED_REASON = 'No se pudo crear la carpeta contenedora.';
    /** Errores de Drive que fallarían igual en todos los ítems restantes. */
    private const FATAL_REASONS = ['auth', 'connection'];

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

        try {
            FilesLog::info('files.drive.import: inicio', ['import_id' => $import->id, 'items' => count($import->source_items ?? [])]);

            $target = null;
            if ($import->target_directory_id) {
                $target = Directory::find($import->target_directory_id);

                if ($target === null) {
                    $import->markFailed('La carpeta destino ya no existe o está en la papelera.');

                    return $import;
                }
            }

            $source = DriveSourceResolver::resolve();
            $fetcher = new DriveFileFetcher($source);
            $mapper = new DriveDirectoryMapper();
            $importer = new DriveFileImporter($fetcher, new DriveFileRefresher($fetcher));

            $plan = (new DriveImportPlanner($source, (int) config('innertia.files.drive.max_items', 2000)))
                ->plan($import->source_items ?? []);

            $import->found = $plan->fileCount();
            foreach ($plan->missing as $id) {
                $import->record('failed', ['external_id' => $id, 'name' => null, 'path' => '', 'reason' => 'El elemento no existe en Drive o se perdió el acceso.']);
            }
            $import->save();

            /** @var array<string, array{dir: Directory, path: string}> $folders */
            $folders = [];
            /** @var array<string, string> $blocked carpetas no creadas (o bajo una no creada) => path */
            $blocked = [];

            foreach ($plan->folders as $entry) {
                $item = $entry['item'];
                $parentKey = $entry['parent_key'];
                $parent = $parentKey !== null ? ($folders[$parentKey] ?? null) : null;
                $parentPath = $parent['path'] ?? ($parentKey !== null ? ($blocked[$parentKey] ?? '') : '');
                $record = ['external_id' => $item->id, 'name' => $item->name, 'path' => $parentPath, 'is_folder' => true];

                if ($parentKey !== null && isset($blocked[$parentKey])) {
                    $blocked[$item->id] = ltrim($parentPath . '/' . $item->name, '/');
                    $import->record('skipped', $record + ['reason' => self::BLOCKED_REASON]);
                    continue;
                }

                try {
                    $dir = $mapper->ensure($item, $parent['dir'] ?? $target, $import);
                } catch (\Throwable $e) {
                    $blocked[$item->id] = ltrim($parentPath . '/' . $item->name, '/');
                    $import->record('failed', $record + ['reason' => $e instanceof LockTimeoutException ? 'Otro proceso está creando esta carpeta.' : $e->getMessage()]);
                    FilesLog::warning('files.drive.import: no se pudo crear una carpeta', ['import_id' => $import->id, 'external_id' => $item->id, 'error' => $e->getMessage()]);
                    continue;
                }

                $folders[$item->id] = ['dir' => $dir, 'path' => ltrim($parentPath . '/' . $dir->name, '/')];
            }

            foreach ($plan->files as $i => $entry) {
                $key = $entry['folder_key'];

                if ($key !== null && isset($blocked[$key])) {
                    $import->record('skipped', ['external_id' => $entry['item']->id, 'name' => $entry['item']->name, 'path' => $blocked[$key], 'reason' => self::BLOCKED_REASON]);
                } else {
                    $folder = $key !== null ? $folders[$key] : null;
                    $this->importOne($importer, $entry['item'], $folder['dir'] ?? $target, $folder['path'] ?? '', $import);
                }

                if (($i + 1) % self::SAVE_EVERY === 0) {
                    $import->save();
                }
            }

            $import->markDone();
            FilesLog::info('files.drive.import: fin', ['import_id' => $import->id, 'imported' => $import->imported, 'updated' => $import->updated, 'failed' => $import->failed_count]);
        } catch (\Throwable $e) {
            $import->markFailed($e->getMessage());
            FilesLog::warning('files.drive.import: falló', ['import_id' => $import->id, 'error' => $e->getMessage()]);
            throw $e;
        }

        return $import;
    }

    /** El worker murió o agotó el timeout: no dejar el registro en `running` para siempre. */
    public function failed(\Throwable $e): void
    {
        FileImport::find($this->importId)?->markFailedIfRunning('El proceso se interrumpió: ' . $e->getMessage());
    }

    private function importOne(DriveFileImporter $importer, DriveItem $item, ?Directory $dir, string $path, FileImport $import): void
    {
        $entry = ['external_id' => $item->id, 'name' => $item->name, 'path' => $path];

        try {
            [$result, $fileId] = $importer->import($item, $dir, $import);
            $import->record($result, $entry + ['file_id' => $fileId]);
        } catch (DriveItemNotImportableException|DriveFileTooLargeException|DriveExportTooLargeException $e) {
            $import->record('skipped', $entry + ['reason' => $e->getMessage()]);
        } catch (LockTimeoutException) {
            $import->record('skipped', $entry + ['reason' => 'Otro proceso está importando este elemento.']);
        } catch (\Throwable $e) {
            if ($e instanceof DriveException && in_array($e->reason, self::FATAL_REASONS, true)) {
                throw $e;
            }

            $import->record('failed', $entry + ['reason' => $e->getMessage()]);
            FilesLog::warning('files.drive.import: falló un elemento', ['import_id' => $import->id, 'external_id' => $item->id, 'error' => $e->getMessage()]);
        }
    }
}
