<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Innertia\Observability\Http\Controllers\ErrorsController;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Telemetry\Models\TelemetryTrace;

uses(RefreshDatabase::class);

beforeEach(function () {
    Route::get('t/errors',              [ErrorsController::class, 'index']);
    Route::get('t/errors/{id}',         [ErrorsController::class, 'show']);
    Route::post('t/errors/{id}/status', [ErrorsController::class, 'status']);
});

it('index devuelve casos con is_critical', function () {
    config(['innertia.observability.critical_threshold' => 5]);
    TelemetryCase::create(['fingerprint' => 'f1', 'title' => 'Boom', 'exception_class' => 'RuntimeException', 'file' => '/a.php', 'line' => 1, 'message_normalized' => 'boom', 'status' => 'open', 'times_seen' => 9, 'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 'tr1']);
    $res = $this->getJson('/t/errors')->assertOk()->json();
    $rows = $res['data'] ?? $res['rows'] ?? $res;
    // is_critical viene de un selectRaw booleano: pgsql→true, sqlite→1. Cast para portabilidad.
    expect((bool) $rows[0]['is_critical'])->toBeTrue();
});

it('show arma caso + trace + eventos + timeline', function () {
    $case = TelemetryCase::create(['fingerprint' => 'f2', 'title' => 'Boom2', 'exception_class' => 'RuntimeException', 'file' => '/a.php', 'line' => 2, 'message_normalized' => 'boom2', 'status' => 'open', 'times_seen' => 1, 'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 'tr2', 'sample_trace_ids' => ['tr2']]);
    TelemetryTrace::create(['trace_id' => 'tr2', 'method' => 'GET', 'route' => '/x', 'url' => 'http://x/x', 'status' => 500, 'occurred_at' => now()]);

    $body = $this->getJson("/t/errors/{$case->id}")->assertOk()->json();
    expect($body)->toHaveKeys(['case', 'trace', 'events', 'timeline', 'is_critical']);
    expect($body['case']['id'])->toBe($case->id);
    expect($body['trace']['route'])->toBe('/x');
});

it('status cambia el estado', function () {
    $case = TelemetryCase::create(['fingerprint' => 'f3', 'title' => 'B', 'exception_class' => 'X', 'file' => '/a', 'line' => 1, 'message_normalized' => 'b', 'status' => 'open', 'times_seen' => 1, 'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 't']);
    $this->postJson("/t/errors/{$case->id}/status", ['status' => 'resolved'])->assertOk();
    expect($case->fresh()->status)->toBe('resolved');
});
