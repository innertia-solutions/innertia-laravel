<?php

use Innertia\InnertiaServiceProvider;

it('exposes organizations config with safe defaults', function () {
    $defaults = require __DIR__ . '/../../config/innertia.php';

    expect($defaults)->toHaveKey('organizations');
    expect($defaults['organizations'])->toMatchArray([
        'enabled'    => false,
        'tables'     => [],
        'column'     => 'organization_id',
        'with_index' => true,
        'model'      => \Innertia\Platform\Organizations\Models\Organization::class,
    ]);
});

it('defaults organizations.enabled to false in published config', function () {
    expect(config('innertia.organizations.enabled'))->toBeFalse();
});
