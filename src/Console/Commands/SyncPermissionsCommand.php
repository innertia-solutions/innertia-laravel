<?php

namespace Innertia\Console\Commands;

use Illuminate\Console\Command;
use Innertia\Facades\Permissions;

class SyncPermissionsCommand extends Command
{
    protected $signature = 'innertia:permissions {--prune : Delete permissions no longer in config}';

    protected $description = 'Sync permissions defined in config/innertia.php to the database';

    public function handle(): int
    {
        $result = Permissions::sync(prune: $this->option('prune'));

        $this->info("Permissions synced.");
        $this->table(['Created', 'Skipped', 'Deleted'], [
            [$result['created'], $result['skipped'], $result['deleted']],
        ]);

        return self::SUCCESS;
    }
}
