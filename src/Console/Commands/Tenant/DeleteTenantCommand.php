<?php

namespace Innertia\Console\Commands\Tenant;

use Illuminate\Console\Command;

class DeleteTenantCommand extends Command
{
    protected $signature = 'tenant:delete
        {key   : The tenant key (slug)}
        {--force : Skip confirmation prompt}';

    protected $description = 'Delete a tenant';

    public function handle(): int
    {
        $model = config('innertia.saas.tenant_model', \Innertia\Models\Tenant::class);

        $key    = $this->argument('key');
        $tenant = $model::findByKey($key);

        if (! $tenant) {
            $this->error("Tenant \"{$key}\" not found.");
            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $confirmed = $this->confirm(
                "Are you sure you want to delete tenant \"{$key}\" ({$tenant->name})? This cannot be undone.",
                false
            );

            if (! $confirmed) {
                $this->info('Aborted.');
                return self::SUCCESS;
            }
        }

        $tenant->delete();

        $this->info("Tenant \"{$key}\" deleted.");

        return self::SUCCESS;
    }
}
