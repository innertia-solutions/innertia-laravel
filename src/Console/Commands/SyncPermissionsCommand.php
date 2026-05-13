<?php

namespace Innertia\Console\Commands;

use Illuminate\Console\Command;
use Innertia\Facades\Permissions;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'innertia:permissions {--prune : Delete permissions no longer in config}';

    protected $description = 'Sync permission descriptions from config/enums to the database (optional — app works without running this)';

    public function handle(): int
    {
        $this->line('Syncing permissions from config to database...');

        $result = Permissions::sync(prune: $this->option('prune'));

        $this->info('Done.');
        $this->table(
            ['Created', 'Updated', 'Skipped', 'Deleted'],
            [[$result['created'], $result['updated'], $result['skipped'], $result['deleted']]],
        );

        if ($result['created'] > 0 || $result['updated'] > 0) {
            $this->line('');
            $this->line('<comment>Tip: commit config/innertia.php alongside enum definitions so descriptions stay in sync.</comment>');
        }

        return self::SUCCESS;
    }
}
