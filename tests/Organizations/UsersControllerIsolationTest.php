<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Innertia\Backoffice\Http\Controllers\UsersController;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Saas\Models\Tenant;
use Innertia\Tests\Organizations\Support\OrgTestUser;

/*
 * Aislamiento del CRUD genérico de usuarios (Backoffice\Routes):
 *   - tenant: solo usuarios con user_contexts en el tenant activo.
 *   - organization: un actor limitado a ciertas orgs solo gestiona usuarios de
 *     esas orgs; el acceso "todas las orgs" (organization_id NULL) solo lo
 *     gestiona otro actor con acceso a todas.
 */

pest()->group('org-enabled');

beforeEach(function () {
    bootOrgOpenMode($this->app);
    config()->set('innertia.auth.user_model', OrgTestUser::class);

    $this->centro = Organization::create(['name' => 'Centro', 'key' => 'centro']);
    $this->norte  = Organization::create(['name' => 'Norte', 'key' => 'norte']);

    // Otro tenant con su propio usuario.
    Tenant::create(['key' => 'machali', 'name' => 'Machalí', 'status' => 'active']);
    Innertia::activate('machali');
    $this->foreign = OrgTestUser::create(['email' => 'b@machali.cl']);
    $this->foreign->grantContext('backoffice');
    Innertia::activate('rancagua');

    $this->ctrl = new class extends UsersController {
        public function scopeIds(): array
        {
            $q = OrgTestUser::query();
            $this->applyManageableScope($q);

            return $q->pluck('id')->sort()->values()->all();
        }
    };
});

function actAs(OrgTestUser $u): void
{
    auth()->setUser($u);
}

function member(?int $orgId = null, ?string $email = null): OrgTestUser
{
    $u = OrgTestUser::create(['email' => $email ?? uniqid() . '@rancagua.cl']);
    $u->grantContext('backoffice', $orgId);

    return $u;
}

// ── Tenant ───────────────────────────────────────────────────────────────────

it('404 al ver un usuario que solo pertenece a otro tenant', function () {
    actAs(member());
    expect(fn () => $this->ctrl->show($this->foreign->id))->toThrow(ModelNotFoundException::class);
});

it('404 al editar un usuario que solo pertenece a otro tenant', function () {
    actAs(member());
    $req = Request::create('/', 'PUT', ['name' => 'X']);
    expect(fn () => $this->ctrl->update($req, $this->foreign->id))->toThrow(ModelNotFoundException::class);
    expect($this->foreign->fresh()->email)->toBe('b@machali.cl');
});

it('404 en sub-recursos por id de un usuario de otro tenant', function () {
    actAs(member());
    $id = $this->foreign->id;
    expect(fn () => $this->ctrl->contexts($id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->ctrl->sessions($id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->ctrl->activity(Request::create('/'), $id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->ctrl->resetPassword(Request::create('/', 'POST', ['mode' => 'email']), $id))
        ->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->ctrl->assignRole(Request::create('/', 'POST', ['role' => 'x']), $id))
        ->toThrow(ModelNotFoundException::class);
});

it('un usuario que está en ambos tenants sí se ve desde este', function () {
    actAs(member());
    $this->foreign->grantContext('backoffice'); // ahora también en rancagua
    expect($this->ctrl->show($this->foreign->id)->getStatusCode())->toBe(200);
});

it('el listado no incluye usuarios de otro tenant', function () {
    $actor = member();
    actAs($actor);
    expect($this->ctrl->scopeIds())->toBe([$actor->id]);
});

it('sin organizations activo aplica solo la barrera de tenant', function () {
    config()->set('innertia.organizations.enabled', false);
    $actor = member();
    $other = member();
    actAs($actor);
    expect($this->ctrl->show($other->id)->getStatusCode())->toBe(200);
    expect(fn () => $this->ctrl->show($this->foreign->id))->toThrow(ModelNotFoundException::class);
});

// ── Organization ─────────────────────────────────────────────────────────────

it('actor con acceso a todas las orgs gestiona a todos los usuarios del tenant', function () {
    $admin = member(null);
    $a = member($this->centro->id);
    $b = member($this->norte->id);
    actAs($admin);

    expect($this->ctrl->show($a->id)->getStatusCode())->toBe(200);
    expect($this->ctrl->show($b->id)->getStatusCode())->toBe(200);
    expect($this->ctrl->scopeIds())->toBe(collect([$admin->id, $a->id, $b->id])->sort()->values()->all());
});

it('actor de una org solo gestiona usuarios de su org', function () {
    $jefe   = member($this->centro->id);
    $propio = member($this->centro->id);
    $ajeno  = member($this->norte->id);
    actAs($jefe);

    expect($this->ctrl->show($propio->id)->getStatusCode())->toBe(200);
    expect(fn () => $this->ctrl->show($ajeno->id))->toThrow(ModelNotFoundException::class);
    expect($this->ctrl->scopeIds())->toBe(collect([$jefe->id, $propio->id])->sort()->values()->all());
});

it('actor de una org no gestiona a un usuario con acceso a todas las orgs', function () {
    $jefe  = member($this->centro->id);
    $admin = member(null);
    actAs($jefe);

    expect(fn () => $this->ctrl->show($admin->id))->toThrow(ModelNotFoundException::class);
    expect(fn () => $this->ctrl->update(Request::create('/', 'PUT', ['name' => 'X']), $admin->id))
        ->toThrow(ModelNotFoundException::class);
});

it('un usuario en dos orgs lo ve el actor de cualquiera de ellas', function () {
    $jefe = member($this->centro->id);
    $u    = member($this->centro->id);
    $u->grantContext('backoffice', $this->norte->id);
    actAs($jefe);

    expect($this->ctrl->show($u->id)->getStatusCode())->toBe(200);
});

it('platform admin no queda limitado por organization (sí por tenant)', function () {
    $root = OrgTestUser::create(['email' => 'root@x.cl', 'is_platform_admin' => true]);
    $b = member($this->norte->id);
    actAs($root);

    expect($this->ctrl->show($b->id)->getStatusCode())->toBe(200);
    expect(fn () => $this->ctrl->show($this->foreign->id))->toThrow(ModelNotFoundException::class);
});

// ── Identidad compartida entre tenants ───────────────────────────────────────

it('no permite cambiar email o contraseña de un usuario que también pertenece a otro tenant', function () {
    actAs(member());
    $this->foreign->grantContext('backoffice'); // está en rancagua y machalí

    expect(fn () => $this->ctrl->update(Request::create('/', 'PUT', ['email' => 'nuevo@x.cl']), $this->foreign->id))
        ->toThrow(ForbiddenException::class);
    expect(fn () => $this->ctrl->update(Request::create('/', 'PUT', ['password' => 'secreto123']), $this->foreign->id))
        ->toThrow(ForbiddenException::class);
    expect(fn () => $this->ctrl->resetPassword(Request::create('/', 'POST', ['mode' => 'manual', 'password' => 'secreto123']), $this->foreign->id))
        ->toThrow(ForbiddenException::class);

    expect($this->foreign->fresh()->email)->toBe('b@machali.cl');
});

it('sí permite cambiar email de un usuario que solo pertenece a este tenant', function () {
    actAs(member());
    $u = member(null, 'viejo@rancagua.cl');

    $res = $this->ctrl->update(Request::create('/', 'PUT', ['email' => 'nuevo@rancagua.cl']), $u->id);
    expect($res->getStatusCode())->toBe(200);
    expect($u->fresh()->email)->toBe('nuevo@rancagua.cl');
});
