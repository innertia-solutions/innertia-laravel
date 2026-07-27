<?php

use Innertia\Telemetry\Health\SystemHealth;

it('collects health with the expected top-level keys', function () {
    $health = (new SystemHealth())->collect();

    expect($health)->toHaveKeys(['memory', 'cpu', 'disk', 'queue', 'php', 'db']);
    expect($health['php'])->toHaveKeys(['version', 'laravel', 'env']);
    expect($health['db'])->toHaveKey('up');
});

it('parses memory limits with units', function () {
    $h = new SystemHealth();
    expect($h->parseMemoryLimit('128M'))->toBe(128 * 1024 * 1024)
        ->and($h->parseMemoryLimit('-1'))->toBeNull();
});
