<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryCase;

uses(RefreshDatabase::class);

it('persiste snapshot como array en el caso', function () {
    $c = TelemetryCase::create([
        'fingerprint' => 'snap1', 'title' => 'B', 'exception_class' => 'X', 'file' => '/a', 'line' => 1,
        'message_normalized' => 'b', 'status' => 'open', 'times_seen' => 1,
        'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 't',
        'snapshot' => ['message' => 'boom', 'stack' => [['function' => 'f', 'in_app' => true]]],
    ]);
    expect($c->fresh()->snapshot['message'])->toBe('boom');
    expect($c->fresh()->snapshot['stack'][0]['in_app'])->toBeTrue();
});
