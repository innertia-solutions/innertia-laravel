<?php

namespace Innertia\Backups\UseCases;

use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Models\Backup;
use Innertia\Platform\Contracts\UseCase;
use Throwable;

class RunBackup extends UseCase
{
    public function __construct(public readonly Backup $backup) {}

    public function execute(): void
    {
        $this->backup->update(['status' => 'running']);

        try {
            app(BackupDriver::class)->create($this->backup);
            $this->backup->update(['status' => 'completed', 'completed_at' => now()]);
        } catch (Throwable $e) {
            $this->backup->update([
                'status'       => 'failed',
                'error'        => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }
}
