<?php

use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent;

it('starts with an empty batch', function () {
    $collector = new TelemetryCollector('my-app', 'session-abc', 'acme', 'local');
    expect($collector->batch())->toBeEmpty();
});

it('records an event and adds it to the batch', function () {
    $collector = new TelemetryCollector('my-app', 'session-abc', 'acme', 'local');

    $event = new TelemetryEvent(
        type: 'query',
        payload: ['sql' => 'select 1'],
        context: ['tenant' => 'acme', 'user_id' => null, 'route' => 'GET /api/test', 'env' => 'local'],
    );

    $collector->record($event);

    expect($collector->batch())->toHaveCount(1)
        ->and($collector->batch()[0]->type)->toBe('query');
});

it('can be flushed', function () {
    $collector = new TelemetryCollector('my-app', 'session-abc', 'acme', 'local');

    $collector->record(new TelemetryEvent(
        type: 'log',
        payload: ['message' => 'test'],
        context: ['tenant' => null, 'user_id' => null, 'route' => 'GET /test', 'env' => 'local'],
    ));

    expect($collector->batch())->toHaveCount(1);

    $flushed = $collector->flush();

    expect($flushed)->toHaveCount(1)
        ->and($collector->batch())->toBeEmpty();
});

it('exposes sessionId', function () {
    $collector = new TelemetryCollector('my-app', 'sess-xyz', 'acme', 'production');
    expect($collector->sessionId())->toBe('sess-xyz');
});
