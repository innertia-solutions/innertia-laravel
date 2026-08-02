<?php
use Innertia\Observability\Database\DatabaseOverview;

it('devuelve la forma de overview sin lanzar', function () {
    $o = (new DatabaseOverview())->collect();
    expect($o)->toHaveKeys([
        'driver', 'database_bytes', 'cache_hit_ratio', 'commits', 'rollbacks',
        'deadlocks', 'temp_files', 'temp_bytes',
    ]);
});
