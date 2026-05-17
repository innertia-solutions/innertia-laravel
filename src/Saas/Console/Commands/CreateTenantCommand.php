<?php

namespace Innertia\Saas\Console\Commands;

use Illuminate\Console\Command;
use Innertia\Exceptions\ConflictException;
use Innertia\Facades\Innertia;
use Innertia\Facades\Permissions;
use Innertia\Saas\UseCases\CreateTenant;
use Innertia\Saas\UseCases\CreateTenantAdmin;

class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {key  : Unique slug identifier for the tenant (e.g. acme)}
        {name : Display name for the tenant (e.g. "Acme Corp")}
        {--status=trial       : Initial status (trial, active, inactive)}
        {--trial-days=14      : Days until trial expires (only when status=trial)}
        {--email=             : Admin email (default: admin@{key}.com)}
        {--admin-name=Admin   : Admin display name}
        {--password=          : Admin password (auto-generated if omitted)}
        {--no-admin           : Skip admin user creation}';

    protected $description = 'Create a new tenant and its initial admin user';

    public function handle(): int
    {
        $key       = $this->argument('key');
        $name      = $this->argument('name');
        $status    = $this->option('status');
        $trialDays = (int) $this->option('trial-days');

        // 1 — Create tenant
        try {
            $tenant = (new CreateTenant($key, $name, $status, $trialDays))->execute();
        } catch (ConflictException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Tenant \"{$key}\" created.");
        $this->table(['ID', 'Key', 'Name', 'Status', 'Trial ends'], [[
            $tenant->id,
            $tenant->key,
            $tenant->name,
            $tenant->status,
            $tenant->trial_ends_at?->format('Y-m-d') ?? '—',
        ]]);

        // 2 — Create admin user
        if ($this->option('no-admin')) {
            return self::SUCCESS;
        }

        Innertia::activate($key);

        Permissions::sync();

        $email     = $this->option('email') ?: "admin@{$key}.com";
        $adminName = $this->option('admin-name');
        $password  = $this->option('password') ?: null;

        try {
            $result = (new CreateTenantAdmin(
                email:    $email,
                name:     $adminName,
                password: $password,
            ))->execute();
        } catch (\Throwable $e) {
            $this->warn("Tenant created but admin user could not be created: " . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Admin user created (demo mode enabled):');
        $this->table(['Email', 'Password'], [[
            $result['email'],
            $result['password'],
        ]]);
        $this->warn('⚠  Save this password — it will not be shown again.');

        Innertia::deactivate();

        return self::SUCCESS;
    }
}
