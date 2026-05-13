<?php

namespace Innertia\Saas\Console\Commands;

use Illuminate\Console\Command;
use Innertia\Exceptions\ConflictException;
use Innertia\Saas\UseCases\CreateTenant;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {key  : Unique slug identifier for the tenant (e.g. acme)}
        {name : Display name for the tenant (e.g. "Acme Corp")}
        {--status=trial       : Initial status (trial, active, inactive)}
        {--trial-days=14      : Days until trial expires (only when status=trial)}';

    protected $description = 'Create a new tenant';

    public function handle(): int
    {
        $key      = $this->argument('key');
        $name     = $this->argument('name');
        $status   = $this->option('status');
        $trialDays = (int) $this->option('trial-days');

        try {
            $tenant = (new CreateTenant($key, $name, $status, $trialDays))->execute();
        } catch (ConflictException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Tenant \"{$key}\" created successfully.");
        $this->table(['ID', 'Key', 'Name', 'Status', 'Trial ends'], [[
            $tenant->id,
            $tenant->key,
            $tenant->name,
            $tenant->status,
            $tenant->trial_ends_at?->format('Y-m-d') ?? '—',
        ]]);

        return self::SUCCESS;
    }
}
