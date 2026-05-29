<?php

/**
 * These tests verify that the app/saas Organization feature (HasOrganization,
 * X-Organization header, OrganizationContext, ResolveOrganizationFromHeader)
 * is INACTIVE in api mode.
 *
 * API mode has its own Organization model (Innertia\Api\Models\Organization)
 * with ApiKeys and hierarchy. That is a separate system, tested in tests/Api/.
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;
use Innertia\Platform\Organizations\OrganizationsFeature;
use Innertia\Platform\Organizations\Middleware\ResolveOrganizationFromHeader;
use Innertia\Platform\Organizations\Middleware\RequireOrganization;
use Innertia\Platform\Traits\HasOrganization;

pest()->group('org-enabled');

class ApiModeOrgModel extends Model {
    use HasOrganization;
    protected $table   = 'api_mode_org_things';
    protected $guarded = [];
    public    $timestamps = false;
}

beforeEach(function () {
    config()->set('innertia.mode', 'api');
    config()->set('innertia.organizations.enabled', true); // even with enabled=true...
    config()->set('database.default', 'testbench');
    config()->set('database.connections.testbench', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);
});

it('OrganizationsFeature::isActive returns false in api mode even when enabled', function () {
    expect(OrganizationsFeature::isActive())->toBeFalse();
});

it('Innertia::organization() returns null in api mode', function () {
    expect(Innertia::organization())->toBeNull();
});

it('HasOrganization trait does NOT inject organization_id in api mode', function () {
    Schema::create('api_mode_org_things', function ($t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('organization_id')->nullable();
        $t->string('name');
    });

    // Even if someone manually sets a context (which shouldn't be possible
    // when feature is inactive, but defense in depth), the trait must not
    // inject organization_id.
    $row = ApiModeOrgModel::create(['name' => 'x']);
    expect($row->organization_id)->toBeNull();
});

it('HasOrganization trait does NOT apply WHERE scope in api mode', function () {
    Schema::create('api_mode_org_things', function ($t) {
        $t->bigIncrements('id');
        $t->unsignedBigInteger('organization_id')->nullable();
        $t->string('name');
    });

    // Create rows with different organization_id values; in api mode all
    // should be visible (no scoping).
    ApiModeOrgModel::create(['name' => 'a', 'organization_id' => 1]);
    ApiModeOrgModel::create(['name' => 'b', 'organization_id' => 2]);
    ApiModeOrgModel::create(['name' => 'c', 'organization_id' => null]);

    expect(ApiModeOrgModel::count())->toBe(3);
});

it('ResolveOrganizationFromHeader middleware is a no-op in api mode', function () {
    $req = Request::create('/foo', 'GET', server: ['HTTP_X_ORGANIZATION' => 'acme']);
    $mw  = new ResolveOrganizationFromHeader();
    $resp = $mw->handle($req, fn () => new \Illuminate\Http\Response('ok'));

    // Should pass through with whatever next returned
    expect($resp)->toBeInstanceOf(\Illuminate\Http\Response::class);
    expect($resp->getContent())->toBe('ok');
    // Context should remain untouched (Innertia::organization() is null in api mode anyway)
    expect(Innertia::organization())->toBeNull();
});

it('RequireOrganization middleware is a no-op in api mode', function () {
    $req = Request::create('/foo', 'GET');
    $resp = (new RequireOrganization())->handle($req, fn () => new \Illuminate\Http\Response('ok'));

    // Should NOT 400 — should pass through
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getContent())->toBe('ok');
});

it('OrganizationsFeature::isActive returns true in app mode when enabled', function () {
    config()->set('innertia.mode', 'app');
    expect(OrganizationsFeature::isActive())->toBeTrue();
});

it('OrganizationsFeature::isActive returns true in saas mode when enabled', function () {
    config()->set('innertia.mode', 'saas');
    expect(OrganizationsFeature::isActive())->toBeTrue();
});
