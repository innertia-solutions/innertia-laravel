<?php

namespace Innertia\Files\Directories\Console;

use Illuminate\Console\Command;
use Innertia\Files\Directories\DirectoriesFeature;
use Innertia\Files\Directories\Events\DirectoryHardDeleted;
use Innertia\Files\Directories\Models\Directory;

class PurgeTrashCommand extends Command
{
    protected $signature = 'innertia:directories:purge-trash {--dry-run}';
    protected $description = 'Hard-delete trashed directories past retention.';

    public function handle(): int
    {
        $days = DirectoriesFeature::trashRetentionDays();

        if ($days === null) {
            $this->info('No retention configured — skipping.');
            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $count = 0;

        Directory::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->chunkById(500, function ($batch) use (&$count) {
                foreach ($batch as $dir) {
                    if (! $this->option('dry-run')) {
                        event(new DirectoryHardDeleted($dir->id, $dir->name));
                        $dir->forceDelete();
                    }
                    $count++;
                }
            });

        $this->info(($this->option('dry-run') ? 'Would purge ' : 'Purged ') . $count . ' directories.');
        return self::SUCCESS;
    }
}
