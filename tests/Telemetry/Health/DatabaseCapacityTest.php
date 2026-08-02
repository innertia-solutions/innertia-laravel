<?php

use Innertia\Telemetry\Health\DatabaseCapacity;

it('devuelve la forma del snapshot de capacidad', function () {
    $snapshot = (new DatabaseCapacity())->collect();

    expect($snapshot)->toHaveKeys(['driver', 'database_bytes', 'tables', 'connections'])
        ->and($snapshot['connections'])->toHaveKeys(['used', 'max']);
});
