<?php

use Innertia\Saas\TenantContext;
use Innertia\Saas\Models\Tenant;

it('starts with no active tenant', function () {
    $ctx = new TenantContext();
    expect($ctx->get())->toBeNull();
});

it('can set and get a tenant', function () {
    $ctx    = new TenantContext();
    $tenant = new Tenant(['key' => 'acme', 'name' => 'Acme']);

    $ctx->set($tenant);

    expect($ctx->get())->toBe($tenant);
});

it('can clear the active tenant', function () {
    $ctx    = new TenantContext();
    $tenant = new Tenant(['key' => 'acme', 'name' => 'Acme']);

    $ctx->set($tenant);
    $ctx->clear();

    expect($ctx->get())->toBeNull();
});
