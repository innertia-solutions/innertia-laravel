<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryTrace;

uses(RefreshDatabase::class);

it('persists a trace with its context snapshot', function () {
    $trace = TelemetryTrace::create([
        'trace_id'         => 'trace-123',
        'session_id'       => 'sess-1',
        'user_id'          => 7,
        'tenant'           => 'acme',
        'method'           => 'POST',
        'route'            => 'POST api/orders',
        'url'              => 'https://app.test/api/orders',
        'view'             => '/orders/new',
        'status'           => 500,
        'duration_ms'      => 42,
        'ip'               => '127.0.0.1',
        'user_agent'       => 'PestUA',
        'payload'          => ['qty' => 2],
        'response_summary' => ['error' => 'boom'],
        'occurred_at'      => now(),
    ]);

    expect($trace->fresh())
        ->trace_id->toBe('trace-123')
        ->payload->toBe(['qty' => 2])
        ->response_summary->toBe(['error' => 'boom']);
});
