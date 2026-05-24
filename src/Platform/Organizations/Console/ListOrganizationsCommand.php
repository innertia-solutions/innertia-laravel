<?php

namespace Innertia\Platform\Organizations\Console;

use Illuminate\Console\Command;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Platform\Organizations\OrganizationsFeature;

class ListOrganizationsCommand extends Command
{
    protected $signature = 'innertia:organization:list
        {--tenant= : Filter by tenant key (default: all tenants)}';

    protected $description = 'List Organizations across tenants.';

    public function handle(): int
    {
        if (! OrganizationsFeature::isActive()) {
            $this->error('Organizations feature is not active.');
            return self::FAILURE;
        }

        $model = config('innertia.organizations.model', Organization::class);
        $query = $model::query();

        if ($tenantKey = $this->option('tenant')) {
            Innertia::activate($tenantKey);
            $tenant = Innertia::tenant();
            if (! $tenant) {
                Innertia::deactivate();
                $this->error("Tenant \"{$tenantKey}\" not found.");
                return self::FAILURE;
            }
            $query->where('tenant_id', $tenant->getKey());
            Innertia::deactivate();
        }

        $orgs = $query->orderBy('tenant_id')->orderBy('name')->get();

        if ($orgs->isEmpty()) {
            $this->info('No organizations found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Tenant ID', 'Key', 'Name', 'Active', 'Created'],
            $orgs->map(fn ($o) => [
                $o->id, $o->tenant_id, $o->key, $o->name,
                $o->active ? 'yes' : 'no',
                $o->created_at?->format('Y-m-d') ?? '—',
            ])->all()
        );

        return self::SUCCESS;
    }
}
