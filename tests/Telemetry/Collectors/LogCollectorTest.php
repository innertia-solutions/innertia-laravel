<?php

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\Collectors\LogCollector;

it('creates a log event', function () {
    $collector = new TelemetryCollector('app', 'sess-1', null, 'local');

    LogCollector::handle($collector, 'error', 'Something failed', ['key' => 'val']);

    $batch = $collector->batch();
    expect($batch)->toHaveCount(1)
        ->and($batch[0]->type)->toBe('log')
        ->and($batch[0]->payload['level'])->toBe('error')
        ->and($batch[0]->payload['message'])->toBe('Something failed')
        ->and($batch[0]->payload['context'])->toBe(['key' => 'val']);
});
