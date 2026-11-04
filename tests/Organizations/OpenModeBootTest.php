<?php

pest()->group('org-enabled');
beforeEach(fn () => bootOrgOpenMode($this->app));

it('activa organizations en modo open con tenant resuelto', function () {
    expect(\Innertia\Platform\Organizations\OrganizationsFeature::isActive())->toBeTrue();
    expect(\Innertia\Facades\Innertia::tenancyEnabled())->toBeTrue();
    expect(\Innertia\Facades\Innertia::tenant()?->key)->toBe('rancagua');
    expect(\Innertia\Facades\Innertia::organization())->not->toBeNull();
});
