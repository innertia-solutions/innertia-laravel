<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryEvent;

uses(RefreshDatabase::class);

it('persists an event linked to a trace', function () {
    $event = TelemetryEvent::create([
        'app'         => 'my-app',
        'session_id'  => 'sess-1',
        'trace_id'    => 'trace-123',
        'type'        => 'mail',
        'occurred_at' => now(),
        'duration_ms' => null,
        'payload'     => ['to' => 'a@b.c'],
        'context'     => ['tenant' => 'acme'],
    ]);

    expect($event->fresh()->trace_id)->toBe('trace-123')
        ->and($event->fresh()->type)->toBe('mail');
});
