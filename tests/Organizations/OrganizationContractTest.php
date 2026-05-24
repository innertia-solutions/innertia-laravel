<?php

use Innertia\Platform\Contracts\OrganizationContract;

it('declares the minimum surface for organization models', function () {
    $r = new ReflectionClass(OrganizationContract::class);

    expect($r->isInterface())->toBeTrue();
    expect($r->hasMethod('getKey'))->toBeTrue();      // numeric id (eloquent)
    expect($r->hasMethod('getRouteKey'))->toBeTrue(); // slug for header lookup
    expect($r->hasMethod('getTenantId'))->toBeTrue();
});

it('declares static findByKey for slug resolution', function () {
    $r = new ReflectionClass(OrganizationContract::class);
    expect($r->hasMethod('findByKey'))->toBeTrue();
    expect($r->getMethod('findByKey')->isStatic())->toBeTrue();
});
