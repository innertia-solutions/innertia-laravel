<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryCase;

uses(RefreshDatabase::class);

it('persists a case grouped by fingerprint', function () {
    $case = TelemetryCase::create([
        'fingerprint'        => str_repeat('a', 64),
        'title'              => 'RuntimeException in Foo.php:42',
        'exception_class'    => \RuntimeException::class,
        'file'               => '/app/Foo.php',
        'line'               => 42,
        'message_normalized' => 'User {n} not found',
        'status'             => 'open',
        'times_seen'         => 1,
        'first_seen'         => now(),
        'last_seen'          => now(),
        'last_trace_id'      => 'trace-1',
        'sample_trace_ids'   => ['trace-1'],
        'notified_at'        => null,
    ]);

    expect($case->fresh())
        ->status->toBe('open')
        ->sample_trace_ids->toBe(['trace-1'])
        ->times_seen->toBe(1);
});
