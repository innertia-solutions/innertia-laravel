<?php

namespace Innertia\Files\Drive\UseCases;

use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Drive\DriveFileFetcher;
use Innertia\Files\Drive\DriveItem;
use Innertia\Files\Drive\DriveSourceResolver;
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
 * no detiene el resto. Un error fatal deja el import en `failed` y se relanza (queda como caso).
 * Idempotente: solo corre desde `pending`.
 */
class ImportFromDrive extends UseCase
{
    private const SAVE_EVERY = 25;

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
        FilesLog::info('files.drive.import: inicio', ['import_id' => $import->id, 'items' => count($import->source_items)]);

        try {
            $source = DriveSourceResolver::resolve();
            $fetcher = new DriveFileFetcher($source);
            $mapper = new DriveDirectoryMapper();
            $importer = new DriveFileImporter($fetcher, new DriveFileRefresher($fetcher));

            $plan = (new DriveImportPlanner($source, (int) config('innertia.files.drive.max_items', 2000)))
                ->plan($import->source_items);

            $import->found = $plan->fileCount();
            foreach ($plan->missing as $id) {
                $import->record('failed', ['external_id' => $id, 'name' => null, 'path' => '', 'reason' => 'El elemento no existe en Drive o se perdió el acceso.']);
            }
            $import->save();

            $target = $import->target_directory_id ? Directory::find($import->target_directory_id) : null;

            /** @var array<string, array{dir: Directory, path: string}> $folders */
            $folders = [];
            foreach ($plan->folders as $entry) {
                $parent = $entry['parent_key'] !== null ? $folders[$entry['parent_key']] : null;
                $dir = $mapper->ensure($entry['item'], $parent['dir'] ?? $target, $import);
                $folders[$entry['item']->id] = [
                    'dir'  => $dir,
                    'path' => ltrim(($parent['path'] ?? '') . '/' . $dir->name, '/'),
                ];
            }

            foreach ($plan->files as $i => $entry) {
                $folder = $entry['folder_key'] !== null ? $folders[$entry['folder_key']] : null;
                $this->importOne($importer, $entry['item'], $folder['dir'] ?? $target, $folder['path'] ?? '', $import);

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

    private function importOne(DriveFileImporter $importer, DriveItem $item, ?Directory $dir, string $path, FileImport $import): void
    {
        $entry = ['external_id' => $item->id, 'name' => $item->name, 'path' => $path];

        try {
            [$result, $fileId] = $importer->import($item, $dir, $import);
            $import->record($result, $entry + ['file_id' => $fileId]);
        } catch (DriveItemNotImportableException|DriveFileTooLargeException|DriveExportTooLargeException $e) {
            $import->record('skipped', $entry + ['reason' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $import->record('failed', $entry + ['reason' => $e->getMessage()]);
            FilesLog::warning('files.drive.import: falló un elemento', ['import_id' => $import->id, 'external_id' => $item->id, 'error' => $e->getMessage()]);
        }
    }
}
