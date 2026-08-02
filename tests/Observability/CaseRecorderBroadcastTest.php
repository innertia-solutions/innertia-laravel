<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Innertia\Telemetry\Cases\CaseRecorder;
use Innertia\Telemetry\Events\CaseTouched;
use Innertia\Telemetry\Models\TelemetryCaseOccurrence;

uses(RefreshDatabase::class);

it('emite CaseTouched y suma al bucket diario al registrar un caso', function () {
    Event::fake([CaseTouched::class]);
    $e = new \RuntimeException('boom en X');
    app(CaseRecorder::class)->record($e, 'trace-1');
    Event::assertDispatched(CaseTouched::class);
    expect(TelemetryCaseOccurrence::sum('count'))->toBe(1);
});

it('reincidencia vuelve a emitir y a sumar', function () {
    Event::fake([CaseTouched::class]);
    $e = new \RuntimeException('boom en X');
    app(CaseRecorder::class)->record($e, 'trace-1');
    app(CaseRecorder::class)->record($e, 'trace-2');
    Event::assertDispatchedTimes(CaseTouched::class, 2);
    expect(TelemetryCaseOccurrence::sum('count'))->toBe(2);
});
