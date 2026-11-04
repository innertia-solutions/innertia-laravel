<?php

use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Tests\Organizations\Support\OrgTestUser;

pest()->group('org-enabled');
beforeEach(function () {
    bootOrgOpenMode($this->app);
    $this->a = Organization::create(['name' => 'Centro', 'key' => 'centro']);
    $this->b = Organization::create(['name' => 'Norte', 'key' => 'norte']);
    $this->off = Organization::create(['name' => 'Cerrada', 'key' => 'cerrada', 'active' => false]);
});

it('operador con una sede accede solo a esa', function () {
    $u = OrgTestUser::create();
    $u->grantContext('backoffice', $this->a->id);
    expect($u->accessibleOrganizationIds())->toBe([$this->a->id]);
    expect($u->canAccessOrganization($this->a->id))->toBeTrue();
    expect($u->canAccessOrganization($this->b->id))->toBeFalse();
});

it('fila NULL = todas las orgs activas del tenant', function () {
    $u = OrgTestUser::create();
    $u->grantContext('backoffice');
    expect($u->accessibleOrganizationIds())->toBe([$this->a->id, $this->b->id]);
});

it('no incluye orgs de otro tenant ni inactivas', function () {
    $u = OrgTestUser::create();
    $u->grantContext('backoffice', $this->off->id);
    expect($u->accessibleOrganizationIds())->toBe([]);

    \Innertia\Saas\Models\Tenant::create(['key' => 'machali', 'name' => 'Machalí', 'status' => 'active']);
    Innertia::activate('machali');
    $x = Organization::create(['name' => 'Machalí 1', 'key' => 'm1']);
    Innertia::activate('rancagua');
    $u->grantContext('backoffice', $x->id);   // fila en rancagua apuntando a org ajena
    expect($u->accessibleOrganizationIds())->toBe([]);
});
