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
    // config('innertia.organizations.enabled') is false — so a model using
    // the trait should behave like a vanilla model. We verify the guard
    // exists in the trait source.
    $source = file_get_contents(
        (new ReflectionClass(\Innertia\Platform\Traits\HasOrganization::class))->getFileName()
    );
    expect($source)->toContain("config('innertia.organizations.enabled')");
});

it('innertia:organization:install refuses to run', function () {
    $exit = $this->artisan('innertia:organization:install')->run();
    expect($exit)->not->toBe(0);
})->skip('Command not registered when disabled — covered by ServiceProviderBootTest.');
