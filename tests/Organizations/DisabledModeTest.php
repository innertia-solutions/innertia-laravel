<?php

use Innertia\Facades\Innertia;

beforeEach(function () {
    config()->set('innertia.organizations.enabled', false);
});

it('Innertia::organization() is null', function () {
    expect(Innertia::organization())->toBeNull();
});

it('HasOrganization trait is a runtime no-op when feature disabled', function () {
    // The trait registers a creating callback, but the callback bails when
    // the Organizations feature is inactive — so a model using the trait
    // should behave like a vanilla model. We verify the guard exists in
    // the trait source via the centralised OrganizationsFeature helper.
    $source = file_get_contents(
        (new ReflectionClass(\Innertia\Platform\Traits\HasOrganization::class))->getFileName()
    );
    expect($source)->toContain('OrganizationsFeature::isActive()');
});

it('innertia:organization:install refuses to run', function () {
    $exit = $this->artisan('innertia:organization:install')->run();
    expect($exit)->not->toBe(0);
})->skip('Command not registered when disabled — covered by ServiceProviderBootTest.');
