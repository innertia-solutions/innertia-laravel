<?php

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\Collectors\QueryCollector;

it('creates a query event from DB query data', function () {
    $collector = new TelemetryCollector('app', 'sess-1', 'acme', 'local');

    QueryCollector::handle($collector, 'select * from users where id = ?', ['42'], 8.5, 'mysql');

    $batch = $collector->batch();
    expect($batch)->toHaveCount(1)
        ->and($batch[0]->type)->toBe('query')
        ->and($batch[0]->payload['sql'])->toBe('select * from users where id = ?')
        ->and($batch[0]->payload['bindings'])->toBe(['42'])
        ->and($batch[0]->payload['duration_ms'])->toBe(8.5)
        ->and($batch[0]->payload['connection'])->toBe('mysql')
        ->and($batch[0]->durationMs)->toBe(8.5);
});
