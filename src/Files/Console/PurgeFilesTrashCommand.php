<?php

namespace Innertia\Files\Console;

use Illuminate\Console\Command;
use Innertia\Files\Models\File;

class PurgeFilesTrashCommand extends Command
{
    protected $signature = 'innertia:files:purge-trash {--dry-run}';
    protected $description = 'Hard-delete trashed files past retention.';

    public function handle(): int
    {
        $days = config('innertia.files.trash_retention_days');

        if ($days === null) {
            $this->info('No retention configured — skipping.');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);
        $count = 0;

        File::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->chunkById(500, function ($batch) use (&$count) {
                foreach ($batch as $file) {
                    if (! $this->option('dry-run')) {
                        $file->forceDelete();
                    }
                    $count++;
                }
            });

        $this->info(($this->option('dry-run') ? 'Would purge ' : 'Purged ') . $count . ' files.');
        return self::SUCCESS;
    }
}
