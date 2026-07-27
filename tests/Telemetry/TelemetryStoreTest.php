<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryTrace;
use Innertia\Telemetry\Store\TelemetryStore;
use Innertia\Telemetry\TelemetryCollector;
use Innertia\Telemetry\TelemetryEvent as Event;

uses(RefreshDatabase::class);

it('persists a trace row and its events tagged with trace_id', function () {
    $collector = new TelemetryCollector('my-app', 'sess-1', 'acme', 'local');
    $collector->record(new Event(
        type: 'query',
        payload: ['sql' => 'select 1'],
        context: ['tenant' => 'acme'],
        durationMs: 1.5,
    ));

    app(TelemetryStore::class)->persist($collector, [
        'method'           => 'POST',
        'route'            => 'POST api/orders',
        'url'              => 'https://app.test/api/orders',
        'status'          => 200,
        'duration_ms'     => 42,
        'ip'              => '127.0.0.1',
        'user_agent'      => 'UA',
        'payload'         => ['password' => 'x', 'qty' => 2],
        'response_summary'=> ['ok' => true],
    ]);

    $trace = TelemetryTrace::first();
    expect($trace->trace_id)->toBe($collector->traceId())
        ->and($trace->payload)->toBe(['password' => '[REDACTED]', 'qty' => 2]);

    expect(TelemetryEvent::where('trace_id', $collector->traceId())->count())->toBe(1);
});

it('redacts sensitive keys inside event payloads too', function () {
    $collector = new TelemetryCollector('my-app', 'sess-1', 'acme', 'local');
    $collector->record(new Event(
        type: 'log',
        payload: ['message' => 'login', 'password' => 'hunter2'],
        context: ['tenant' => 'acme'],
    ));

    app(TelemetryStore::class)->persist($collector, []);

    $event = TelemetryEvent::where('trace_id', $collector->traceId())->first();
    expect($event->payload)->toBe(['message' => 'login', 'password' => '[REDACTED]']);
});
