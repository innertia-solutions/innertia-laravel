<?php

use Innertia\Auth\RBAC\Services\PermissionsService;
use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;

beforeEach(function () {
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);
});

it('cacheKey includes organization id when feature enabled', function () {
    config()->set('innertia.organizations.enabled', true);
    Innertia::organization()->set(99);

    $svc = new PermissionsService();
    $key = (new ReflectionClass($svc))->getMethod('cacheKey');
    $key->setAccessible(true);

    expect($key->invoke($svc, 'user-1'))->toContain('.99.');
});

it('cacheKey does not include organization id when feature disabled', function () {
    config()->set('innertia.organizations.enabled', false);
    $svc = new PermissionsService();
    $key = (new ReflectionClass($svc))->getMethod('cacheKey');
    $key->setAccessible(true);

    expect($key->invoke($svc, 'user-1'))->not->toContain('.99.');
});
