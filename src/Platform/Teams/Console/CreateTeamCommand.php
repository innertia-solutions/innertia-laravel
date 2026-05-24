<?php

namespace Innertia\Platform\Teams\Console;

use Illuminate\Console\Command;
use Innertia\Facades\Innertia;
use Innertia\Platform\Teams\Models\Team;
use Innertia\Platform\Teams\TeamsFeature;
use Innertia\Platform\Teams\UseCases\CreateTeam;

class CreateTeamCommand extends Command
{
    protected $signature = 'innertia:team:create
        {tenant : Tenant key (e.g. acme)}
        {name   : Team display name (e.g. "Quality Committee")}
        {--description= : Optional description}
        {--parent= : Parent team id (for nested teams)}
        {--org= : Organization id (when Organizations feature is active)}';

    protected $description = 'Create a Team within a tenant (and optionally an organization).';

    public function handle(): int
    {
        if (! TeamsFeature::isActive()) {
            $this->error('Teams feature is not active. Set INNERTIA_TEAMS_ENABLED=true.');
            return self::FAILURE;
        }

        $tenantKey = $this->argument('tenant');
        Innertia::activate($tenantKey);
        $tenant = Innertia::tenant();
        if (! $tenant) {
            Innertia::deactivate();
            $this->error("Tenant \"{$tenantKey}\" not found.");
            return self::FAILURE;
        }

        try {
            $team = (new CreateTeam(
                tenantId:       $tenant->getKey(),
                name:           $this->argument('name'),
                description:    $this->option('description'),
                parentTeamId:   $this->option('parent'),
                organizationId: $this->option('org'),
            ))->execute();
        } catch (\Throwable $e) {
            $this->error("Failed to create team: " . $e->getMessage());
            return self::FAILURE;
        } finally {
            Innertia::deactivate();
        }

        $this->info("Team \"{$team->name}\" created.");
        $this->table(
            ['ID', 'Tenant', 'Name', 'Parent', 'Org'],
            [[$team->id, $tenantKey, $team->name, $team->parent_team_id ?? '—', $team->organization_id ?? '—']]
        );

        return self::SUCCESS;
    }
}
