<?php

use Innertia\Version\VersionString;

it('parsea una versión de producción', function () {
    expect(VersionString::parse('v1.0.2-20261111.2'))->toBe([
        'raw'         => 'v1.0.2-20261111.2',
        'semver'      => '1.0.2',
        'date'        => '2026-11-11',
        'environment' => 'production',
        'sequence'    => 2,
    ]);
});

it('parsea una versión de QA', function () {
    expect(VersionString::parse('v1.0.2-20261111-QA.7'))->toBe([
        'raw'         => 'v1.0.2-20261111-QA.7',
        'semver'      => '1.0.2',
        'date'        => '2026-11-11',
        'environment' => 'qa',
        'sequence'    => 7,
    ]);
});

it('parsea una versión de dev', function () {
    expect(VersionString::parse('v1.0.2-dev'))->toBe([
        'raw'         => 'v1.0.2-dev',
        'semver'      => '1.0.2',
        'date'        => null,
        'environment' => 'dev',
        'sequence'    => null,
    ]);
});

it('devuelve null si no calza el formato', function (?string $raw) {
    expect(VersionString::parse($raw))->toBeNull();
})->with([null, '', '1.0.2', 'v.1.0.2-20261111.2', 'v1.0.2-20261111', 'v1.0.2-20261111-qa.1', 'v1.0-20261111.1']);
