<?php

namespace Innertia\Backups\Console;

use Illuminate\Console\Command;
use Innertia\Backups\Support\BackupLog;
use Innertia\Backups\UseCases\PruneBackups;

class PruneBackupsCommand extends Command
{
    protected $signature = 'innertia:backups:prune
        {--days= : Override de retention_days}
        {--keep= : Override de keep}
        {--dry-run : Solo contar, sin borrar}';

    protected $description = 'Elimina respaldos antiguos según la retención (retention_days / keep).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $count = (new PruneBackups(
            retentionDays: $this->option('days') !== null ? (int) $this->option('days') : null,
            keep: $this->option('keep') !== null ? (int) $this->option('keep') : null,
            dryRun: $dry,
        ))->execute();

        if (! $dry) {
            BackupLog::write('info', "Poda de respaldos: {$count} eliminados.", ['pruned' => $count]);
        }

        $this->info(($dry ? 'Se eliminarían ' : 'Eliminados ') . $count . ' respaldos.');

        return self::SUCCESS;
    }
}
