<?php

use Illuminate\Support\Facades\DB;
use Innertia\Auth\RBAC\Models\Role;
use Innertia\Facades\Innertia;
use Innertia\Tests\Organizations\Support\OrgTestUser;

pest()->group('org-enabled');
beforeEach(function () {
    bootOrgOpenMode($this->app);
    $this->tid = (string) Innertia::tenant()->getKey();
    $this->role = Role::create(['name' => 'operator', 'tenant_id' => $this->tid]);
    $this->u = OrgTestUser::create(['email' => 'op@x.cl']);
});

it('permite el mismo rol en dos orgs sin pisar la primera', function () {
    $this->u->assignRole('operator', 10);
    $this->u->assignRole('operator', 20);

    $orgs = DB::table('model_roles')->where('model_id', $this->u->id)->pluck('organization_id')->sort()->values()->all();
    expect($orgs)->toBe([10, 20]);

    Innertia::organization()->set(10);
    expect($this->u->hasRole('operator'))->toBeTrue();
    Innertia::organization()->set(20);
    expect($this->u->hasRole('operator'))->toBeTrue();
    Innertia::organization()->set(30);
    expect($this->u->hasRole('operator'))->toBeFalse();
});

it('es idempotente por (rol, org) incluida la global', function () {
    $this->u->assignRole('operator', 10);
    $this->u->assignRole('operator', 10);
    $this->u->assignRole('operator');      // sin org activa → global (NULL)
    $this->u->assignRole('operator');
    expect(DB::table('model_roles')->where('model_id', $this->u->id)->count())->toBe(2);
});

it('removeRole con org quita solo esa org', function () {
    $this->u->assignRole('operator', 10);
    $this->u->assignRole('operator', 20);
    $this->u->removeRole('operator', 10);
    expect(DB::table('model_roles')->where('model_id', $this->u->id)->pluck('organization_id')->all())->toBe([20]);
});

it('removeRole sin org quita todas (compatibilidad)', function () {
    $this->u->assignRole('operator', 10);
    $this->u->assignRole('operator', 20);
    $this->u->removeRole('operator');
    expect(DB::table('model_roles')->where('model_id', $this->u->id)->count())->toBe(0);
});
