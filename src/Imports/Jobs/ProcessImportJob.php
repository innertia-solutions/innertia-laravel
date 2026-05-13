<?php

namespace Innertia\Imports\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Innertia\Imports\Importer;
use Innertia\Files\Models\File;
use Innertia\Platform\Models\Process;
use Innertia\Platform\Events\ProcessFailed;
use Throwable;

class ProcessImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600; // 10 minutes

    public function __construct(
        private readonly Process $process,
        private readonly File    $file,
        private readonly string  $importerClass,
    ) {}

    public function handle(): void
    {
        /** @var Importer $importer */
        $importer = new $this->importerClass();
        $importer->hydrateFromJob($this->process, $this->file);
        $importer->run();
    }

    public function failed(Throwable $exception): void
    {
        $this->process->fail($exception->getMessage());
        ProcessFailed::dispatch($this->process);
    }
}
