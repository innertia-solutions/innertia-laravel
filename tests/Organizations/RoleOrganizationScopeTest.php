<?php

use Illuminate\Support\Facades\Schema;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;

pest()->group('org-enabled');

beforeEach(function () {
    config()->set('innertia.mode', 'saas');
    config()->set('innertia.organizations.enabled', true);
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);

    Schema::create('roles', function ($t) {
        $t->uuid('id')->primary();
        $t->string('tenant_id')->nullable();
        $t->unsignedBigInteger('organization_id')->nullable();
        $t->string('name');
        $t->string('description')->nullable();
        $t->timestamps();
    });

    Schema::create('entity_history', function ($t) {
        $t->bigIncrements('id');
        $t->string('entity_type');
        $t->string('entity_id');
        $t->string('action');
        $t->json('changes')->nullable();
        $t->json('old_values')->nullable();
        $t->json('new_values')->nullable();
        $t->string('user_id')->nullable();
        $t->string('ip_address')->nullable();
        $t->text('reason')->nullable();
        $t->timestamp('created_at');
    });
});

it('findByName resolves a global (org NULL) role when no org context', function () {
    Role::create(['name' => 'admin', 'tenant_id' => 't1', 'organization_id' => null]);
    expect(Role::findByName('admin', 't1'))->not->toBeNull();
});

it('findByName prefers a scoped role when an org is active', function () {
    Role::create(['name' => 'admin', 'tenant_id' => 't1', 'organization_id' => null]);
    Role::create(['name' => 'admin', 'tenant_id' => 't1', 'organization_id' => 99]);

    Innertia::organization()->set(99);

    $role = Role::findByName('admin', 't1');
    expect($role->organization_id)->toBe(99);
});

it('findByName falls back to a global role when no scoped role exists', function () {
    Role::create(['name' => 'admin', 'tenant_id' => 't1', 'organization_id' => null]);
    Innertia::organization()->set(7);

    $role = Role::findByName('admin', 't1');
    expect($role)->not->toBeNull();
    expect($role->organization_id)->toBeNull();
});

it('explicit $organizationId argument wins over context', function () {
    Role::create(['name' => 'admin', 'tenant_id' => 't1', 'organization_id' => 5]);
    Innertia::organization()->set(99);

    $role = Role::findByName('admin', 't1', organizationId: 5);
    expect($role->organization_id)->toBe(5);
});
