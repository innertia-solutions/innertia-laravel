<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Cases\CaseRecorder;
use Innertia\Telemetry\Models\TelemetryCase;

uses(RefreshDatabase::class);

function boom(string $msg = 'User 1 not found'): \RuntimeException
{
    return new \RuntimeException($msg);
}

it('creates a new case on first occurrence and marks it new', function () {
    $recorder = app(CaseRecorder::class);

    $result = $recorder->record(boom(), 'trace-1');

    expect($result['is_new'])->toBeTrue()
        ->and(TelemetryCase::count())->toBe(1)
        ->and(TelemetryCase::first())
            ->times_seen->toBe(1)
            ->status->toBe('open');
});

it('increments times_seen and does not flag new on recurrence', function () {
    $recorder = app(CaseRecorder::class);

    $recorder->record(boom('User 1 not found'), 'trace-1');
    $result = $recorder->record(boom('User 55 not found'), 'trace-2'); // mismo fingerprint

    expect($result['is_new'])->toBeFalse()
        ->and($result['is_reopened'])->toBeFalse()
        ->and(TelemetryCase::count())->toBe(1)
        ->and(TelemetryCase::first())
            ->times_seen->toBe(2)
            ->last_trace_id->toBe('trace-2');
});

it('reopens a resolved case on recurrence', function () {
    $recorder = app(CaseRecorder::class);
    $recorder->record(boom(), 'trace-1');
    TelemetryCase::first()->update(['status' => 'resolved']);

    $result = $recorder->record(boom(), 'trace-2');

    expect($result['is_reopened'])->toBeTrue()
        ->and(TelemetryCase::first()->status)->toBe('open');
});

it('keeps sample_trace_ids capped at 5, newest first, deduplicated', function () {
    $recorder = app(CaseRecorder::class);

    foreach (['t1', 't2', 't3', 't4', 't5', 't6', 't6'] as $trace) {
        $recorder->record(boom(), $trace);
    }

    $samples = TelemetryCase::first()->sample_trace_ids;

    expect($samples)->toHaveCount(5)
        ->and($samples[0])->toBe('t6')   // más reciente primero
        ->and($samples)->not->toContain('t1'); // el más viejo cae fuera del cap
});
