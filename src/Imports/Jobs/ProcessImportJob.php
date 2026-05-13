<?php

namespace Innertia\Imports\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Innertia\Imports\Events\ImportFailed;
use Innertia\Imports\Importer;
use Innertia\Models\ImportRecord;
use Throwable;

/**
 * Queued job that executes an Importer synchronously in a worker.
 *
 * Dispatched automatically by Importer::queue(). Do not dispatch manually.
 * The file is always read from Storage — never from the original upload.
 */
class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Max attempts before Laravel marks the job as failed. */
    public int $tries = 1;

    /** Max execution time in seconds (10 minutes for large files). */
    public int $timeout = 600;

    public function __construct(
        private readonly ImportRecord $record,
        private readonly string $importerClass,
    ) {}

    public function handle(): void
    {
        /** @var Importer $importer */
        $importer = new $this->importerClass();

        // Rehydrate the importer with the persisted record so run() uses it
        $importer->hydrateRecord($this->record);

        $importer->run();
    }

    public function failed(Throwable $exception): void
    {
        $this->record->markFailed($exception->getMessage());

        ImportFailed::dispatch($this->record);
    }
}
