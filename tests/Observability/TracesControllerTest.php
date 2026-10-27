<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Innertia\Observability\Http\Controllers\TracesController;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\Models\TelemetryTrace;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::get('t/traces/{traceId}', [TracesController::class, 'show']);
});

it('resuelve trace + eventos + logs + caso vinculado', function () {
    TelemetryTrace::create(['trace_id' => 'trX', 'method' => 'POST', 'route' => '/x', 'url' => 'http://x/x', 'status' => 200, 'occurred_at' => now()]);
    TelemetryEvent::create(['app' => 'a', 'session_id' => 's', 'trace_id' => 'trX', 'type' => 'query', 'payload' => ['sql' => 'select 1'], 'context' => [], 'occurred_at' => now()]);
    TelemetryEvent::create(['app' => 'a', 'session_id' => 's', 'trace_id' => 'trX', 'type' => 'log', 'payload' => ['level' => 'error', 'message' => 'boom'], 'context' => [], 'occurred_at' => now()]);
    TelemetryCase::create(['fingerprint' => 'fX', 'title' => 'Boom', 'exception_class' => 'RuntimeException', 'file' => '/a.php', 'line' => 1, 'message_normalized' => 'boom', 'status' => 'open', 'times_seen' => 1, 'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 'trX']);

    $body = $this->getJson('/t/traces/trX')->assertOk()->json();

    expect($body)->toHaveKeys(['trace', 'events', 'logs', 'case']);
    expect($body['trace']['route'])->toBe('/x');
    expect($body['events'])->toHaveCount(2);
    expect($body['logs'])->toHaveCount(1);
    expect($body['case']['title'])->toBe('Boom');
});

it('trace inexistente devuelve 404', function () {
    $this->getJson('/t/traces/nope')->assertStatus(404);
});
