<?php

use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;
use Innertia\InnertiaManager;

beforeEach(function () {
    config()->set('innertia.organizations.enabled', true);
    $this->app->singleton(OrganizationContext::class);
    // Rebuild manager with org context wired
    $this->app->forgetInstance(InnertiaManager::class);
});

it('Innertia::organization() returns the OrganizationContext singleton', function () {
    $ctx = Innertia::organization();
    expect($ctx)->toBeInstanceOf(OrganizationContext::class);
    expect($ctx)->toBe(app(OrganizationContext::class));
});

it('organization() is null when feature is disabled', function () {
    config()->set('innertia.organizations.enabled', false);
    $this->app->forgetInstance(InnertiaManager::class);
    expect(Innertia::organization())->toBeNull();
});
