<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Auth\RBAC\Traits\HasRoles;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;

pest()->group('org-enabled');

class TestUserModel extends Model
{
    use HasRoles;
    protected $table   = 'test_users';
    protected $guarded = [];
    public    $timestamps = false;
}

beforeEach(function () {
    config()->set('innertia.mode', 'saas');
    config()->set('innertia.organizations.enabled', true);
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);

    Schema::create('test_users', fn ($t) => $t->bigIncrements('id'));
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
});

it('hasRole returns true for a globally-assigned role regardless of org', function () {
    $u = TestUserModel::create();
    $r = Role::create(['name' => 'admin', 'tenant_id' => null, 'organization_id' => null]);
    $u->roles()->attach($r->id, ['organization_id' => null]);

    Innertia::organization()->set(7);
    expect($u->hasRole('admin'))->toBeTrue();
});

it('hasRole returns true only for the org where the role was granted', function () {
    $u = TestUserModel::create();
    $r = Role::create(['name' => 'editor', 'tenant_id' => null, 'organization_id' => 10]);
    $u->roles()->attach($r->id, ['organization_id' => 10]);

    Innertia::organization()->set(10);
    expect($u->hasRole('editor'))->toBeTrue();

    Innertia::organization()->set(20);
    expect($u->hasRole('editor'))->toBeFalse();
});

it('hasRole accepts an explicit organizationId argument', function () {
    $u = TestUserModel::create();
    $r = Role::create(['name' => 'editor', 'tenant_id' => null, 'organization_id' => 10]);
    $u->roles()->attach($r->id, ['organization_id' => 10]);

    Innertia::organization()->set(20);
    expect($u->hasRole('editor', organizationId: 10))->toBeTrue();
});
