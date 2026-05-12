<?php

namespace Innertia\Console\Commands\Tenant;

use Illuminate\Console\Command;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {key  : Unique slug identifier for the tenant (e.g. acme)}
        {name : Display name for the tenant (e.g. "Acme Corp")}
        {--status=trial       : Initial status (trial, active, inactive)}
        {--trial-days=14      : Days until trial expires (only when status=trial)}
        {--domain=            : Optional domain to attach (e.g. acme.myapp.com)}';

    protected $description = 'Create a new tenant';

    public function handle(): int
    {
        $model = config('innertia.saas.tenant_model', \Innertia\Models\Tenant::class);

        $key    = $this->argument('key');
        $name   = $this->argument('name');
        $status = $this->option('status');

        // Validate key is unique
        if ($model::findByKey($key)) {
            $this->error("A tenant with key \"{$key}\" already exists.");
            return self::FAILURE;
        }

        $data = [
            'key'    => $key,
            'name'   => $name,
            'status' => $status,
        ];

        if ($status === 'trial' && $this->option('trial-days')) {
            $data['trial_ends_at'] = now()->addDays((int) $this->option('trial-days'));
        }

        $tenant = $model::create($data);

        if ($domain = $this->option('domain')) {
            $tenant->domains()->create(['domain' => $domain]);
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
