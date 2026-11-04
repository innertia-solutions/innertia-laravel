<?php

use Innertia\Auth\Models\UserContext;
use Innertia\Platform\Organizations\Models\Organization;
use Innertia\Tests\Organizations\Support\OrgTestUser;

pest()->group('org-enabled');
beforeEach(fn () => bootOrgOpenMode($this->app));

it('guarda organization_id al otorgar contexto por org', function () {
    $a = Organization::create(['name' => 'CESFAM Centro', 'key' => 'centro']);
    $b = Organization::create(['name' => 'CESFAM Norte', 'key' => 'norte']);
    $u = OrgTestUser::create(['email' => 'op@x.cl']);

    $u->grantContext('backoffice', $a->id);
    $u->grantContext('backoffice', $b->id);

    $ids = UserContext::where('user_id', $u->id)->pluck('organization_id')->sort()->values()->all();
    expect($ids)->toBe([$a->id, $b->id]);
});

it('no duplica la fila global (organization_id NULL)', function () {
    $u = OrgTestUser::create(['email' => 'admin@x.cl']);
    $u->grantContext('backoffice');
    $u->grantContext('backoffice');
    expect(UserContext::where('user_id', $u->id)->whereNull('organization_id')->count())->toBe(1);
});
