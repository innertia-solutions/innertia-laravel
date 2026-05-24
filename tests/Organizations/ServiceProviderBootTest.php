<?php

it('does NOT register organization middleware when feature disabled', function () {
    config()->set('innertia.organizations.enabled', false);
    $router = app('router');
    expect($router->getMiddleware())->not->toHaveKey('organization.resolve');
    expect($router->getMiddleware())->not->toHaveKey('organization.require');
});

it('registers organization middleware aliases when feature enabled', function () {
    config()->set('innertia.organizations.enabled', true);

    // Re-boot the provider so the conditional aliases are registered.
    $provider = new \Innertia\InnertiaServiceProvider($this->app);
    $provider->boot();

    $aliases = app('router')->getMiddleware();
    expect($aliases)->toHaveKey('organization.resolve');
    expect($aliases)->toHaveKey('organization.require');
    expect($aliases['organization.resolve'])->toBe(
        \Innertia\Platform\Organizations\Middleware\ResolveOrganizationFromHeader::class
    );
})->group('org-enabled');
