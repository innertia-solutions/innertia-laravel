<?php
use Innertia\Observability\Support\Criticality;
use Innertia\Telemetry\Models\TelemetryCase;

it('critico solo si abierto y supera el umbral', function () {
    config(['innertia.observability.critical_threshold' => 10]);
    $open = new TelemetryCase(['status' => 'open', 'times_seen' => 10]);
    $low  = new TelemetryCase(['status' => 'open', 'times_seen' => 3]);
    $resolved = new TelemetryCase(['status' => 'resolved', 'times_seen' => 99]);
    expect(Criticality::isCritical($open))->toBeTrue();
    expect(Criticality::isCritical($low))->toBeFalse();
    expect(Criticality::isCritical($resolved))->toBeFalse();
});
