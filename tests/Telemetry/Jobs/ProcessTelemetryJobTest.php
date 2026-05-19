<?php

use Innertia\Telemetry\Jobs\ProcessTelemetryJob;

it('processes a telemetry batch', function () {
    $batch = [
        'app'        => 'documentia',
        'session_id' => 'sess-abc',
        'events' => [
            [
                'type'        => 'query',
                'payload'     => ['sql' => 'select * from users'],
                'context'     => ['tenant' => 'acme', 'user_id' => null, 'route' => 'GET /api/users', 'env' => 'local'],
                'duration_ms' => 5.0,
                'occurred_at' => now()->toAtomString(),
            ],
            [
                'type'        => 'log',
                'payload'     => ['level' => 'info', 'message' => 'hello'],
                'context'     => ['tenant' => 'acme', 'user_id' => null, 'route' => 'GET /api/users', 'env' => 'local'],
                'duration_ms' => null,
                'occurred_at' => now()->toAtomString(),
            ],
        ],
    ];

    $job = new ProcessTelemetryJob($batch);

    expect($job)->toBeInstanceOf(ProcessTelemetryJob::class)
        ->and($job->tries)->toBe(3);
});
