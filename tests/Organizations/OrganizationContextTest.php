<?php

use Innertia\Platform\Organizations\OrganizationContext;

it('starts empty', function () {
    $ctx = new OrganizationContext();
    expect($ctx->current())->toBeNull();
    expect($ctx->scope())->toBe([]);
    expect($ctx->inConsolidatedView())->toBeFalse();
});

it('set() updates current and resets scope to that single id', function () {
    $ctx = new OrganizationContext();
    $ctx->set(42);
    expect($ctx->current())->toBe(42);
    expect($ctx->scope())->toBe([42]);
});

it('setScope() updates scope without touching current', function () {
    $ctx = new OrganizationContext();
    $ctx->set(42);
    $ctx->setScope([1, 2, 3]);
    expect($ctx->current())->toBe(42);
    expect($ctx->scope())->toBe([1, 2, 3]);
    expect($ctx->inConsolidatedView())->toBeTrue();
});

it('clear() resets both current and scope', function () {
    $ctx = new OrganizationContext();
    $ctx->set(42);
    $ctx->setScope([1, 2, 3]);
    $ctx->clear();
    expect($ctx->current())->toBeNull();
    expect($ctx->scope())->toBe([]);
});

it('withOrganization() runs the callback with the org set then restores', function () {
    $ctx = new OrganizationContext();
    $ctx->set(1);
    $seen = null;
    $result = $ctx->withOrganization(99, function () use (&$seen, $ctx) {
        $seen = $ctx->current();
        return 'ok';
    });
    expect($seen)->toBe(99);
    expect($result)->toBe('ok');
    expect($ctx->current())->toBe(1);
    expect($ctx->scope())->toBe([1]);
});

it('inConsolidatedView() is true only when scope has more than one or differs from [current]', function () {
    $ctx = new OrganizationContext();
    $ctx->set(5);
    expect($ctx->inConsolidatedView())->toBeFalse();
    $ctx->setScope([5, 6]);
    expect($ctx->inConsolidatedView())->toBeTrue();
});
