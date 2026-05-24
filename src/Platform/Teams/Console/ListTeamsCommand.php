<?php

namespace Innertia\Platform\Teams\Console;

use Illuminate\Console\Command;
use Innertia\Facades\Innertia;
use Innertia\Platform\Teams\Models\Team;
use Innertia\Platform\Teams\TeamsFeature;

class ListTeamsCommand extends Command
{
    protected $signature = 'innertia:team:list
        {--tenant= : Filter by tenant key (default: all tenants)}';

    protected $description = 'List Teams across tenants.';

    public function handle(): int
    {
        if (! TeamsFeature::isActive()) {
            $this->error('Teams feature is not active.');
            return self::FAILURE;
        }

        $model = config('innertia.teams.model', Team::class);
        $query = $model::query()->withCount('members');

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

        $teams = $query->orderBy('tenant_id')->orderBy('name')->get();

        if ($teams->isEmpty()) {
            $this->info('No teams found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Tenant', 'Name', 'Members', 'Parent', 'Org'],
            $teams->map(fn ($t) => [
                $t->id,
                $t->tenant_id,
                $t->name,
                $t->members_count,
                $t->parent_team_id ?? '—',
                $t->organization_id ?? '—',
            ])->all()
        );

        return self::SUCCESS;
    }
}
