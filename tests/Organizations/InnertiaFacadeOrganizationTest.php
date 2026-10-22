<?php

use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;
use Innertia\InnertiaManager;

pest()->group('org-enabled');

beforeEach(function () {
    config()->set('innertia.organizations.enabled', true);
    $this->app->singleton(OrganizationContext::class);
    // Rebuild manager with org context wired. forgetInstance drops the container
    // instance, but the Innertia facade caches its own resolved manager
    // (Facade::$resolvedInstance) — clear it too so the next Innertia::organization()
    // resolves a fresh manager holding the current OrganizationContext.
    $this->app->forgetInstance(InnertiaManager::class);
    \Illuminate\Support\Facades\Facade::clearResolvedInstance(InnertiaManager::class);
});

it('Innertia::organization() returns the OrganizationContext singleton', function () {
    $ctx = Innertia::organization();
    expect($ctx)->toBeInstanceOf(OrganizationContext::class);
    expect($ctx)->toBe(app(OrganizationContext::class));
});

it('organization() is null when feature is disabled', function () {
    config()->set('innertia.organizations.enabled', false);
    $this->app->forgetInstance(InnertiaManager::class);
    \Illuminate\Support\Facades\Facade::clearResolvedInstance(InnertiaManager::class);
    expect(Innertia::organization())->toBeNull();
});
