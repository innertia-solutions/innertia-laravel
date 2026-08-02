<?php
use Innertia\Observability\Database\DatabaseQueries;

it('devuelve available + rows sin lanzar', function () {
    $q = (new DatabaseQueries())->collect();
    expect($q)->toHaveKeys(['available', 'reason', 'top']);
    expect($q['available'])->toBeBool();
    expect($q['top'])->toBeArray();
    if (! $q['available']) {
        expect($q['reason'])->toBeString();
        expect($q['top'])->toBe([]);
    }
});
