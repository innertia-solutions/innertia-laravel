<?php

use Illuminate\Http\Request;
use Innertia\Auth\Http\Controllers\AuthController;
use Innertia\Auth\RBAC\Models\Permission;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\Middleware\ResolveOrganizationFromHeader;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Tests\Organizations\Support\OrgTestUser;

pest()->group('org-enabled');
beforeEach(function () {
    bootOrgOpenMode($this->app);
    $tid = (string) Innertia::tenant()->getKey();
    $this->a = Organization::create(['name' => 'Centro', 'key' => 'centro']);
    $this->b = Organization::create(['name' => 'Norte', 'key' => 'norte']);
    $coord = Role::create(['name' => 'site_coordinator', 'tenant_id' => $tid]);
    $p = Permission::create(['name' => 'campaigns.manage', 'tenant_id' => $tid]);
    $coord->permissions()->attach($p->id);
    Role::create(['name' => 'operator', 'tenant_id' => $tid]);

    $this->u = OrgTestUser::create(['email' => 'x@x.cl']);
    $this->u->grantContext('backoffice', $this->a->id);
    $this->u->grantContext('backoffice', $this->b->id);
    $this->u->assignRole('operator', $this->a->id);
    $this->u->assignRole('site_coordinator', $this->b->id);
});

function meAs($user, string $uri = '/auth/me'): array
{
    $req = tap(Request::create($uri), fn ($r) => $r->setUserResolver(fn () => $user));
    $controller = app(AuthController::class);

    return ($uri === '/auth/me/permissions' ? $controller->mePermissions($req) : $controller->me($req))->getData(true);
}

it('me() con X-Organization devuelve org activa y permisos de esa org', function () {
    Innertia::organization()->set($this->b->id);
    $json = meAs($this->u);
    expect($json['current_organization']['key'])->toBe('norte');
    expect($json['permissions'])->toContain('campaigns.manage');
});

it('me() en la otra org no hereda permisos de la primera', function () {
    Innertia::organization()->set($this->a->id);
    expect(meAs($this->u)['permissions'])->not->toContain('campaigns.manage');
});

it('me/permissions también filtra roles por org activa', function () {
    Innertia::organization()->set($this->a->id);
    $json = meAs($this->u, '/auth/me/permissions');
    expect($json['permissions'])->not->toContain('campaigns.manage');
    expect($json['roles'])->toBe(['operator']);
});

it('me() lista las orgs accesibles por contexto', function () {
    $json = meAs($this->u);
    expect(collect($json['organizations']['backoffice'])->pluck('key')->sort()->values()->all())->toBe(['centro', 'norte']);
});

it('las rutas de auth del modo open incluyen la resolución de org', function () {
    \Innertia\Saas\Auth\Routes::register();
    $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'auth/me');
    expect($route->gatherMiddleware())->toContain(ResolveOrganizationFromHeader::class);
});

it('sin tenant activo (open, pre-selección) ignora X-Organization en vez de 403', function () {
    Innertia::deactivate();
    $req = Request::create('/auth/my-gyms', 'GET', server: ['HTTP_X_ORGANIZATION' => 'centro']);
    $req->setUserResolver(fn () => $this->u);
    $resp = (new ResolveOrganizationFromHeader())->handle($req, fn () => new \Illuminate\Http\Response('ok'));
    expect($resp->getStatusCode())->toBe(200);
    expect(Innertia::organization()->current())->toBeNull();
});
