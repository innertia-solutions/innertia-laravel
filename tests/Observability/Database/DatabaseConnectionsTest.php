<?php
use Innertia\Observability\Database\DatabaseConnections;

it('devuelve la forma de conexiones sin lanzar', function () {
    $c = (new DatabaseConnections())->collect();
    expect($c)->toHaveKeys(['driver', 'total', 'max', 'by_state', 'long_running', 'idle_in_transaction']);
    expect($c['by_state'])->toBeArray();
    expect($c['long_running'])->toBeArray();
});
