<?php

use Innertia\InnertiaManager;
use Innertia\Saas\TenantContext;
use Innertia\Saas\Models\Tenant;
use Innertia\Exceptions\NotFoundException;

function makeManager(bool $isSaas = true): InnertiaManager
{
    $ctx = new TenantContext();
    return new InnertiaManager($ctx, $isSaas);
}

// ── App mode (no-ops) ────────────────────────────────────────────────────────

it('returns null from tenant() in app mode', function () {
    $mgr = makeManager(false);
    expect($mgr->tenant())->toBeNull();
});

it('returns null from tenant(key) in app mode', function () {
    $mgr = makeManager(false);
    expect($mgr->tenant('anything'))->toBeNull();
});

it('activate() is a no-op in app mode', function () {
    $mgr = makeManager(false);
    expect($mgr->activate('anything'))->toBeNull();
});

it('deactivate() is a no-op in app mode', function () {
    $mgr = makeManager(false);
    $mgr->deactivate(); // should not throw
    expect(true)->toBeTrue();
});

// ── SaaS mode ────────────────────────────────────────────────────────────────

it('returns null when no tenant is active in saas mode', function () {
    $mgr = makeManager(true);
    expect($mgr->tenant())->toBeNull();
});

it('returns the active tenant when set', function () {
    $ctx    = new TenantContext();
    $tenant = new Tenant(['key' => 'acme', 'name' => 'Acme']);
    $ctx->set($tenant);

    $mgr = new InnertiaManager($ctx, true);

    expect($mgr->tenant())->toBe($tenant);
});

it('deactivate() clears the active tenant', function () {
    $ctx    = new TenantContext();
    $tenant = new Tenant(['key' => 'acme', 'name' => 'Acme']);
    $ctx->set($tenant);

    $mgr = new InnertiaManager($ctx, true);
    $mgr->deactivate();

    expect($mgr->tenant())->toBeNull();
});
