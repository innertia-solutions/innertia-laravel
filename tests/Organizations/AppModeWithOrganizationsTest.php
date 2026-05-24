<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Auth\RBAC\Services\PermissionsService;
use Innertia\Auth\RBAC\Traits\HasApps;
use Innertia\Auth\RBAC\Traits\HasRoles;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;
use Innertia\Platform\Traits\HasOrganization;

pest()->group('org-enabled');

class AppModeOrgModel extends Model
{
    use HasOrganization;
    protected $table      = 'app_mode_org_things';
    protected $guarded    = [];
    public    $timestamps = false;
}

class AppModeUserModel extends Model
{
    use HasRoles;
    use HasApps;
    protected $table      = 'app_mode_users';
    protected $guarded    = [];
    public    $timestamps = false;
}

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    config()->set('innertia.organizations.enabled', true);
    config()->set('innertia.apps', [
        'backoffice'  => 'Administración',
        'technicians' => 'Portal Técnicos',
    ]);
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);

    // Re-bind singletons so InnertiaManager picks up mode=app
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);

    Schema::create('app_mode_org_things', function ($t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('organization_id')->nullable();
        $t->string('name');
    });

    Schema::create('app_mode_users', function ($t) {
        $t->bigIncrements('id');
        $t->string('name')->nullable();
    });

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

    Schema::create('model_roles', function ($t) {
        $t->string('model_type');
        $t->string('model_id');
        $t->uuid('role_id');
        $t->unsignedBigInteger('organization_id')->nullable();
        $t->primary(['model_type', 'model_id', 'role_id', 'organization_id']);
    });

    // Mirror the app-mode user_apps schema (no tenant_id column)
    Schema::create('user_apps', function ($t) {
        $t->bigIncrements('id');
        $t->string('user_id');
        $t->string('app');
        $t->timestamps();
        $t->unique(['user_id', 'app']);
    });
});

it('Innertia::organization() returns the singleton in app mode and tenant() is null', function () {
    expect(Innertia::organization())->toBeInstanceOf(OrganizationContext::class);
    expect(Innertia::tenant())->toBeNull();
});

it('HasOrganization trait scopes by organization_id only (no tenant filter)', function () {
    // Seed three rows in different orgs.
    Innertia::organization()->set(1);
    AppModeOrgModel::create(['name' => 'one']);
    Innertia::organization()->set(2);
    AppModeOrgModel::create(['name' => 'two']);
    Innertia::organization()->set(3);
    AppModeOrgModel::create(['name' => 'three']);

    Innertia::organization()->set(2);
    expect(AppModeOrgModel::pluck('name')->all())->toBe(['two']);

    Innertia::organization()->setScope([1, 3]);
    expect(AppModeOrgModel::pluck('name')->all())->toBe(['one', 'three']);

    Innertia::organization()->clear();
    expect(AppModeOrgModel::count())->toBe(3);
});

it('Role::findByName works in app mode + organizations without tenant filter', function () {
    Role::create(['name' => 'admin', 'tenant_id' => null, 'organization_id' => null]);
    Role::create(['name' => 'admin', 'tenant_id' => null, 'organization_id' => 50]);

    // No org context → global role.
    $global = Role::findByName('admin');
    expect($global)->not->toBeNull();
    expect($global->organization_id)->toBeNull();

    // Org 50 active → scoped role wins.
    Innertia::organization()->set(50);
    $scoped = Role::findByName('admin');
    expect($scoped)->not->toBeNull();
    expect($scoped->organization_id)->toBe(50);

    // Org 99 active (no match) → falls back to global.
    Innertia::organization()->set(99);
    $fallback = Role::findByName('admin');
    expect($fallback)->not->toBeNull();
    expect($fallback->organization_id)->toBeNull();
});

it('HasRoles::hasRole scopes by model_roles.organization_id in app mode', function () {
    $u = AppModeUserModel::create();

    // Global role assignment.
    $rGlobal = Role::create(['name' => 'admin', 'tenant_id' => null, 'organization_id' => null]);
    $u->roles()->attach($rGlobal->id, ['organization_id' => null]);

    // Per-org role assignment.
    $rOrg = Role::create(['name' => 'editor', 'tenant_id' => null, 'organization_id' => 10]);
    $u->roles()->attach($rOrg->id, ['organization_id' => 10]);

    // Global role is visible everywhere.
    Innertia::organization()->set(10);
    expect($u->hasRole('admin'))->toBeTrue();
    Innertia::organization()->set(99);
    expect($u->hasRole('admin'))->toBeTrue();

    // Per-org role is visible only in its org.
    Innertia::organization()->set(10);
    expect($u->hasRole('editor'))->toBeTrue();
    Innertia::organization()->set(20);
    expect($u->hasRole('editor'))->toBeFalse();

    // Explicit organizationId argument wins.
    Innertia::organization()->set(20);
    expect($u->hasRole('editor', organizationId: 10))->toBeTrue();
});

it('PermissionsService::cacheKey format in app mode is innertia.perms.{orgId}.{userId}', function () {
    Innertia::organization()->set(42);

    $svc = new PermissionsService();
    $ref = (new ReflectionClass($svc))->getMethod('cacheKey');
    $ref->setAccessible(true);

    $key = $ref->invoke($svc, 'user-1');

    expect($key)->toContain('.42.');
    expect($key)->toBe('innertia.perms.42.user-1');
});

it('user_apps is orthogonal to organization context (HasApps smoke check)', function () {
    $u = AppModeUserModel::create();
    $u->grantApp('backoffice');

    // App access holds regardless of current org context.
    Innertia::organization()->clear();
    expect($u->hasApp('backoffice'))->toBeTrue();

    Innertia::organization()->set(1);
    expect($u->hasApp('backoffice'))->toBeTrue();

    Innertia::organization()->set(2);
    expect($u->hasApp('backoffice'))->toBeTrue();

    // Unknown app stays false.
    expect($u->hasApp('technicians'))->toBeFalse();
});
