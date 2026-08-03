<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Innertia\Observability\Http\Controllers\ErrorsController;
use Innertia\Telemetry\Models\TelemetryCase;

uses(RefreshDatabase::class);

it('show incluye el snapshot durable', function () {
    Route::get('t/errors/{id}', [ErrorsController::class, 'show']);
    $case = TelemetryCase::create([
        'fingerprint' => 's1', 'title' => 'B', 'exception_class' => 'X', 'file' => '/a', 'line' => 1,
        'message_normalized' => 'b', 'status' => 'open', 'times_seen' => 1,
        'first_seen' => now(), 'last_seen' => now(), 'last_trace_id' => 't',
        'snapshot' => ['message' => 'boom', 'stack' => [['function' => 'f', 'in_app' => true, 'context' => []]], 'breadcrumbs' => [], 'request' => null, 'previous' => []],
    ]);
    $body = $this->getJson("/t/errors/{$case->id}")->assertOk()->json();
    expect($body)->toHaveKey('snapshot');
    expect($body['snapshot']['message'])->toBe('boom');
});
