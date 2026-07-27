<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Cases\ReviewPromptBuilder;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryTrace;

uses(RefreshDatabase::class);

it('builds a review prompt embedding case + trace context', function () {
    $case = TelemetryCase::create([
        'fingerprint' => str_repeat('a', 64), 'title' => 'RuntimeException in Foo.php:42',
        'exception_class' => \RuntimeException::class, 'file' => '/app/Foo.php', 'line' => 42,
        'message_normalized' => 'User {n} not found', 'status' => 'open', 'times_seen' => 3,
        'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 'trace-1',
        'sample_trace_ids' => ['trace-1'],
    ]);
    TelemetryTrace::create([
        'trace_id' => 'trace-1', 'method' => 'POST', 'route' => 'POST api/orders',
        'url' => 'https://app.test/api/orders', 'status' => 500, 'occurred_at' => now(),
        'payload' => ['qty' => 2], 'response_summary' => ['error' => 'boom'],
    ]);
    TelemetryEvent::create([
        'app' => 'a', 'session_id' => 's', 'trace_id' => 'trace-1', 'type' => 'log',
        'occurred_at' => now(), 'payload' => ['message' => 'about to fail'], 'context' => [],
    ]);

    $prompt = (new ReviewPromptBuilder())->forCase($case);

    expect($prompt)
        ->toContain('RuntimeException')
        ->toContain('POST api/orders')
        ->toContain('about to fail')
        ->toContain('times_seen');
});
