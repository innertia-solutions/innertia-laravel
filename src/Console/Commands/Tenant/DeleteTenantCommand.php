<?php

namespace Innertia\Console\Commands\Tenant;

use Illuminate\Console\Command;
use Innertia\Exceptions\NotFoundException;
use Innertia\Tenants\UseCases\DeleteTenant;

class DeleteTenantCommand extends Command
{
    protected $signature = 'tenant:delete
        {key   : The tenant key (slug)}
        {--force : Skip confirmation prompt}';

    protected $description = 'Delete a tenant';

    public function handle(): int
    {
        $key = $this->argument('key');

        // Resolve tenant for confirmation prompt before deleting
        $model  = config('innertia.saas.tenant_model', \Innertia\Models\Tenant::class);
        $tenant = $model::where('key', $key)->first();

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

        try {
            (new DeleteTenant($key))->execute();
        } catch (NotFoundException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Tenant \"{$key}\" deleted.");

        return self::SUCCESS;
    }
}
