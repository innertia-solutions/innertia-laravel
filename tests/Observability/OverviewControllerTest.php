<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Innertia\Observability\Http\Controllers\OverviewController;
use Innertia\Telemetry\Models\TelemetryCase;

uses(RefreshDatabase::class);

it('overview devuelve la estructura de KPIs', function () {
    config(['innertia.observability.critical_threshold' => 5]);
    TelemetryCase::create(['fingerprint' => 'o1', 'title' => 'B', 'exception_class' => 'X', 'file' => '/a', 'line' => 1, 'message_normalized' => 'b', 'status' => 'open', 'times_seen' => 9, 'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 't']);
    Route::get('t/overview', [OverviewController::class, 'index']);

    $body = $this->getJson('/t/overview')->assertOk()->json();
    expect($body)->toHaveKeys(['errors', 'database', 'slow_queries', 'queue']);
    expect($body['errors'])->toHaveKeys(['open', 'resolved', 'ignored', 'critical']);
    expect($body['errors']['open'])->toBe(1);
    expect($body['errors']['critical'])->toBe(1);
});
