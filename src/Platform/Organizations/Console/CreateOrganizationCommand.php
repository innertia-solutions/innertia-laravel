<?php

namespace Innertia\Platform\Organizations\Console;

use Illuminate\Console\Command;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationsFeature;
use Innertia\Platform\Organizations\UseCases\CreateOrganization;

class CreateOrganizationCommand extends Command
{
    protected $signature = 'innertia:organization:create
        {tenant : Tenant key (e.g. acme)}
        {key    : Unique slug for the organization within the tenant (e.g. north-region)}
        {name   : Display name for the organization (e.g. "North Region")}
        {--inactive : Crear como inactive (default: active)}';

    protected $description = 'Create an Organization within a tenant.';

    public function handle(): int
    {
        if (! OrganizationsFeature::isActive()) {
            $this->error('Organizations feature is not active. Set INNERTIA_ORGANIZATIONS_ENABLED=true and ensure innertia.mode is not "api".');
            return self::FAILURE;
        }

        $tenantKey = $this->argument('tenant');
        $key       = $this->argument('key');
        $name      = $this->argument('name');

        Innertia::activate($tenantKey);
        $tenant = Innertia::tenant();

        if (! $tenant) {
            $this->error("Tenant \"{$tenantKey}\" not found.");
            return self::FAILURE;
        }

        try {
            $org = (new CreateOrganization(
                tenantId: $tenant->getKey(),
                name:     $name,
                key:      $key,
                active:   ! $this->option('inactive'),
            ))->execute();
        } catch (\Throwable $e) {
            $this->error("Failed to create organization: " . $e->getMessage());
            return self::FAILURE;
        } finally {
            Innertia::deactivate();
        }

        $this->info("Organization \"{$key}\" created.");
        $this->table(['ID', 'Tenant', 'Key', 'Name', 'Active'], [[
            $org->id, $tenantKey, $org->key, $org->name, $org->active ? 'yes' : 'no',
        ]]);

        return self::SUCCESS;
    }
}
