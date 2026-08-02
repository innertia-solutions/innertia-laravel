<?php
use Innertia\Observability\Database\DatabaseIndexes;

it('devuelve la forma de índices sin lanzar', function () {
    $i = (new DatabaseIndexes())->collect();
    expect($i)->toHaveKeys(['driver', 'unused', 'scans']);
    expect($i['unused'])->toBeArray();
    expect($i['scans'])->toBeArray();
});
