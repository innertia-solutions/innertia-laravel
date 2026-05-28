<?php

namespace Innertia\Saas\UseCases;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Innertia\Facades\Innertia;
use Innertia\Platform\Contracts\UseCase;

/**
 * Creates the initial admin user for a tenant and grants access to all configured contexts.
 *
 * Usage:
 *   Innertia::activate($tenantKey);
 *   $result = (new CreateTenantAdmin(email: 'admin@acme.com', name: 'Admin'))->execute();
 *   // $result['password'] — plain-text password (only returned once)
 */
class CreateTenantAdmin extends UseCase
{
    public function __construct(
        public readonly string $email,
        public readonly string $name = 'Admin',
        public readonly ?string $password = null,
    ) {}

    public function execute(): array
    {
        $model    = config('auth.providers.users.model');
        $password = $this->password ?? Str::password(12, symbols: false);

        $user = $model::create([
            'name'     => $this->name,
            'email'    => $this->email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        // Grant access to every context defined in config
        $contexts = array_keys(config('innertia.contexts', []));
        if ($contexts && method_exists($user, 'grantContext')) {
            $user->grantContext($contexts);
        }

        // Assign admin role — create it scoped to the current tenant if it doesn't exist yet
        if (method_exists($user, 'assignRole')) {
            $adminRole = config('innertia.saas.admin_role', 'super-admin');
            $tenantId  = Innertia::tenant() ? (string) Innertia::tenant()->getKey() : null;
            \Innertia\Auth\RBAC\Models\Role::firstOrCreate([
                'name'      => $adminRole,
                'tenant_id' => $tenantId,
            ]);
            $user->assignRole($adminRole);
        }

        // Enable demo mode automatically so every new tenant starts in demo
        $tenant = Innertia::tenant();
        if ($tenant) {
            (new EnableTenantDemo((string) $tenant->key, $user->email, $password))->execute();
        }

        return [
            'user'     => $user,
            'email'    => $user->email,
            'password' => $password,
        ];
    }
}
