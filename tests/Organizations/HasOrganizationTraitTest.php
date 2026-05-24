<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;
use Innertia\Platform\Traits\HasOrganization;

pest()->group('org-enabled');

class TestOrgScopedModel extends Model
{
    use HasOrganization;
    protected $table    = 'org_scoped_things';
    protected $guarded  = [];
    public    $timestamps = false;
}

beforeEach(function () {
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);
    Schema::create('org_scoped_things', function ($t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('organization_id')->nullable();
        $t->string('name');
    });
});

it('is a no-op when organizations.enabled = false', function () {
    config()->set('innertia.organizations.enabled', false);
    $row = TestOrgScopedModel::create(['name' => 'x']);
    expect($row->organization_id)->toBeNull();
    expect(TestOrgScopedModel::count())->toBe(1);
});

it('injects organization_id on creating when feature enabled and current() is set', function () {
    config()->set('innertia.organizations.enabled', true);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);
    Innertia::organization()->set(7);

    $row = TestOrgScopedModel::create(['name' => 'a']);
    expect($row->organization_id)->toBe(7);
});

it('does NOT inject organization_id when current() is null (CLI/job mode)', function () {
    config()->set('innertia.organizations.enabled', true);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);

    $row = TestOrgScopedModel::create(['name' => 'a']);
    expect($row->organization_id)->toBeNull();
});

it('scopes queries by WHERE organization_id IN scope()', function () {
    config()->set('innertia.organizations.enabled', true);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);

    Innertia::organization()->set(1);
    TestOrgScopedModel::create(['name' => 'one']);
    Innertia::organization()->set(2);
    TestOrgScopedModel::create(['name' => 'two']);
    Innertia::organization()->set(3);
    TestOrgScopedModel::create(['name' => 'three']);

    Innertia::organization()->set(1);
    expect(TestOrgScopedModel::pluck('name')->all())->toBe(['one']);

    Innertia::organization()->setScope([1, 2]);
    expect(TestOrgScopedModel::pluck('name')->all())->toBe(['one', 'two']);
});

it('does NOT apply the global scope when scope() is empty (CLI/job)', function () {
    config()->set('innertia.organizations.enabled', true);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);

    Innertia::organization()->set(1);
    TestOrgScopedModel::create(['name' => 'one']);
    Innertia::organization()->set(2);
    TestOrgScopedModel::create(['name' => 'two']);

    Innertia::organization()->clear();
    expect(TestOrgScopedModel::count())->toBe(2);
});
