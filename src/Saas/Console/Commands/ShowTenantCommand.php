<?php

namespace Innertia\Saas\Console\Commands;

use Illuminate\Console\Command;

class ShowTenantCommand extends Command
{
    protected $signature = 'tenant:show {key : The tenant key (slug)}';

    protected $description = 'Show details of a tenant';

    public function handle(): int
    {
        $model = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);

        $tenant = $model::findByKey($this->argument('key'));

        if (! $tenant) {
            $this->error("Tenant \"{$this->argument('key')}\" not found.");
            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['ID',         $tenant->id],
            ['Key',        $tenant->key],
            ['Name',       $tenant->name],
            ['Status',     $tenant->status],
            ['Trial ends', $tenant->trial_ends_at?->format('Y-m-d H:i') ?? '—'],
            ['Created',    $tenant->created_at->format('Y-m-d H:i')],
            ['Updated',    $tenant->updated_at->format('Y-m-d H:i')],
        ]);

        if ($tenant->domains && $tenant->domains->isNotEmpty()) {
            $this->newLine();
            $this->line('<fg=gray>Domains:</>');
            $this->table(['Domain'], $tenant->domains->map(fn ($d) => [$d->domain])->toArray());
        }

        return self::SUCCESS;
    }
}
