<?php

namespace Innertia\Console\Commands\Tenant;

use Illuminate\Console\Command;

class ListTenantsCommand extends Command
{
    protected $signature = 'tenant:list {--status= : Filter by status (trial, active, inactive)}';

    protected $description = 'List all tenants';

    public function handle(): int
    {
        $model = config('innertia.saas.tenant_model', \Innertia\Models\Tenant::class);

        $query = $model::query();

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $tenants = $query->orderBy('created_at')->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Key', 'Name', 'Status', 'Trial ends', 'Created'],
            $tenants->map(fn ($t) => [
                $t->id,
                $t->key,
                $t->name,
                $t->status,
                $t->trial_ends_at?->format('Y-m-d') ?? '—',
                $t->created_at->format('Y-m-d'),
            ])->toArray()
        );

        return self::SUCCESS;
    }
}
