<?php

use Innertia\Geo\Geo;

it('lista las 16 regiones de Chile', function () {
    expect(Geo::regions())->toHaveCount(16);
});

it('resuelve el nombre de una región por código CUT', function () {
    expect(Geo::regionName('13'))->toBe('Región Metropolitana de Santiago');
});

it('lista comunas de una región', function () {
    $communes = Geo::communes('13');
    expect($communes)->not->toBeEmpty();
    expect(collect($communes)->pluck('code'))->toContain('13123');
});

it('resuelve el nombre de una comuna por código CUT', function () {
    expect(Geo::communeName('13123'))->toBe('Providencia');
});

it('valida que una comuna pertenezca a una región', function () {
    expect(Geo::communeBelongsToRegion('13123', '13'))->toBeTrue();
    expect(Geo::communeBelongsToRegion('13123', '15'))->toBeFalse();
    expect(Geo::communeBelongsToRegion('99999', '13'))->toBeFalse();
});
