<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Innertia\Telemetry\Http\Controllers\ObservabilityErrorsController;
use Innertia\Telemetry\Models\TelemetryCase;

uses(RefreshDatabase::class);

it('cambia el estado de un caso', function () {
    $case = TelemetryCase::create([
        'fingerprint' => 'abc', 'title' => 'X', 'exception_class' => 'E',
        'file' => '/x', 'line' => 1, 'message_normalized' => 'x',
        'status' => 'open', 'times_seen' => 1, 'first_seen' => now(), 'last_seen' => now(),
    ]);

    $request = Request::create('/x', 'POST', ['status' => 'resolved']);
    (new ObservabilityErrorsController())->status($request, (string) $case->id);

    expect($case->fresh()->status)->toBe('resolved');
});

it('rechaza estados inválidos', function () {
    $case = TelemetryCase::create([
        'fingerprint' => 'def', 'title' => 'X', 'exception_class' => 'E',
        'file' => '/x', 'line' => 1, 'message_normalized' => 'x',
        'status' => 'open', 'times_seen' => 1, 'first_seen' => now(), 'last_seen' => now(),
    ]);

    $request = Request::create('/x', 'POST', ['status' => 'bogus']);

    expect(fn () => (new ObservabilityErrorsController())->status($request, (string) $case->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
