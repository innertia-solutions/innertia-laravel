<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryPresence;
use Innertia\Telemetry\Models\TelemetryTrace;

uses(RefreshDatabase::class);

it('prunes traces/events older than retention_days but keeps recent cases', function () {
    config()->set('innertia.telemetry.retention_days', 7);
    config()->set('innertia.telemetry.case_retention_days', 90);

    TelemetryTrace::create(['trace_id' => 'old', 'occurred_at' => now()->subDays(10)]);
    TelemetryTrace::create(['trace_id' => 'new', 'occurred_at' => now()->subDay()]);
    TelemetryEvent::create(['app' => 'a', 'session_id' => 's', 'trace_id' => 'old', 'type' => 'log', 'occurred_at' => now()->subDays(10), 'payload' => [], 'context' => []]);
    TelemetryCase::create(['fingerprint' => str_repeat('d', 64), 'title' => 't', 'exception_class' => 'E', 'file' => '/f', 'line' => 1, 'message_normalized' => 'm', 'status' => 'open', 'times_seen' => 1, 'first_seen' => now()->subDays(10), 'last_seen' => now()->subDays(10), 'sample_trace_ids' => []]);
    TelemetryPresence::create(['session_id' => 'p1', 'last_seen_at' => now()->subMinutes(5)]);

    $this->artisan('innertia:telemetry:prune')->assertSuccessful();

    expect(TelemetryTrace::count())->toBe(1)
        ->and(TelemetryEvent::count())->toBe(0)
        ->and(TelemetryCase::count())->toBe(1)   // 10 días < 90, se conserva
        ->and(TelemetryPresence::count())->toBe(0); // presence caduca por TTL (5 min > 60s)
});
