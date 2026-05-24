<?php

use Innertia\Facades\Innertia;
use Innertia\Platform\Organizations\OrganizationContext;

pest()->group('org-enabled');

beforeEach(function () {
    config()->set('innertia.organizations.enabled', true);
    $this->app->singleton(OrganizationContext::class);
    $this->app->forgetInstance(\Innertia\InnertiaManager::class);
});

it('Innertia::organization() returns the singleton', function () {
    expect(Innertia::organization())->toBeInstanceOf(OrganizationContext::class);
});

it('set then withOrganization preserves outer state', function () {
    Innertia::organization()->set(1);
    Innertia::organization()->withOrganization(2, function () {
        expect(Innertia::organization()->current())->toBe(2);
    });
    expect(Innertia::organization()->current())->toBe(1);
});
