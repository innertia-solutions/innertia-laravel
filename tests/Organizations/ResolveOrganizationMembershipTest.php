<?php

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\Middleware\ResolveOrganizationFromHeader;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Tests\Organizations\Support\OrgTestUser;

pest()->group('org-enabled');
beforeEach(function () {
    bootOrgOpenMode($this->app);
    $this->a = Organization::create(['name' => 'Centro', 'key' => 'centro']);
    $this->b = Organization::create(['name' => 'Norte', 'key' => 'norte']);
    $this->off = Organization::create(['name' => 'Cerrada', 'key' => 'cerrada', 'active' => false]);
});

function orgRequest(?OrgTestUser $user, string $slug, bool $consolidated = false): \Symfony\Component\HttpFoundation\Response
{
    $server = ['HTTP_X_ORGANIZATION' => $slug];
    if ($consolidated) {
        $server['HTTP_X_CONSOLIDATED'] = 'true';
    }
    $req = Request::create('/x', 'GET', server: $server);
    if ($user) {
        $req->setUserResolver(fn () => $user);
    }

    return (new ResolveOrganizationFromHeader())->handle($req, fn () => new Response('ok'));
}

it('deja pasar a un miembro y fija la org', function () {
    $u = OrgTestUser::create();
    $u->grantContext('backoffice', $this->a->id);
    expect(orgRequest($u, 'centro')->getStatusCode())->toBe(200);
    expect(Innertia::organization()->current())->toBe($this->a->id);
});

it('403 si el usuario no pertenece a la org', function () {
    $u = OrgTestUser::create();
    $u->grantContext('backoffice', $this->a->id);
    expect(orgRequest($u, 'norte')->getStatusCode())->toBe(403);
});

it('403 si la org está inactiva', function () {
    $u = OrgTestUser::create();
    $u->grantContext('backoffice');
    expect(orgRequest($u, 'cerrada')->getStatusCode())->toBe(403);
});

it('platform admin entra a cualquier org activa del tenant', function () {
    $u = OrgTestUser::create(['is_platform_admin' => true]);
    expect(orgRequest($u, 'norte')->getStatusCode())->toBe(200);
});

it('consolidado = orgs accesibles del usuario', function () {
    $u = OrgTestUser::create();
    $u->grantContext('backoffice');
    orgRequest($u, 'centro', consolidated: true);
    expect(Innertia::organization()->scope())->toBe([$this->a->id, $this->b->id]);
});

it('sin usuario autenticado no valida membresía (rutas públicas)', function () {
    expect(orgRequest(null, 'centro')->getStatusCode())->toBe(200);
});
