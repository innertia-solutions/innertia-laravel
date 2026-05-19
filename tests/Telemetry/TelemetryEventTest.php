<?php

use Innertia\Telemetry\TelemetryEvent;

it('creates a telemetry event with all fields', function () {
    $event = new TelemetryEvent(
        type: 'query',
        payload: ['sql' => 'select * from users', 'duration_ms' => 5.2],
        context: ['tenant' => 'acme', 'user_id' => 'uuid-123', 'route' => 'GET /api/users', 'env' => 'local'],
        durationMs: 5.2,
    );

    expect($event->type)->toBe('query')
        ->and($event->payload['sql'])->toBe('select * from users')
        ->and($event->context['tenant'])->toBe('acme')
        ->and($event->durationMs)->toBe(5.2)
        ->and($event->occurredAt)->toBeInstanceOf(\DateTimeImmutable::class);
});

it('serializes to array correctly', function () {
    $event = new TelemetryEvent(
        type: 'log',
        payload: ['level' => 'error', 'message' => 'Something broke'],
        context: ['tenant' => null, 'user_id' => null, 'route' => 'CLI', 'env' => 'production'],
    );

    $arr = $event->toArray();

    expect($arr)->toHaveKeys(['type', 'payload', 'context', 'duration_ms', 'occurred_at'])
        ->and($arr['type'])->toBe('log')
        ->and($arr['duration_ms'])->toBeNull();
});
