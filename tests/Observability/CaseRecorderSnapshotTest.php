<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Cases\CaseRecorder;
use Innertia\Telemetry\Models\TelemetryCase;

uses(RefreshDatabase::class);

it('guarda snapshot al crear el caso', function () {
    app(CaseRecorder::class)->record(new \RuntimeException('boom recorder'), 'tr-snap');
    $case = TelemetryCase::first();
    expect($case->snapshot)->toBeArray();
    expect($case->snapshot['message'])->toBe('boom recorder');
    expect($case->snapshot['stack'])->not->toBeEmpty();
});

it('actualiza el snapshot al reincidir', function () {
    $r = app(CaseRecorder::class);
    $e = new \RuntimeException('boom recorder');
    $r->record($e, 'tr-1');
    $r->record($e, 'tr-2');
    $case = TelemetryCase::first();
    expect($case->times_seen)->toBe(2);
    expect($case->snapshot['message'])->toBe('boom recorder');
});
