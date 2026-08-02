<?php
use Innertia\Observability\Database\DatabaseTables;

it('devuelve la forma de tablas sin lanzar', function () {
    $t = (new DatabaseTables())->collect();
    expect($t)->toHaveKeys(['driver', 'tables']);
    expect($t['tables'])->toBeArray();
});
