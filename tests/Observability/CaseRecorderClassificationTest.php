<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Cases\CaseRecorder;
use Innertia\Telemetry\Models\TelemetryCase;
use Innertia\Facades\Telemetry;
uses(RefreshDatabase::class);
it('guarda origin/category/tags al registrar', function () {
    Telemetry::tag('gym', 'acme');
    app(CaseRecorder::class)->record(new \RuntimeException('boom'), 'tr-cls');
    $c = TelemetryCase::first();
    expect($c->origin)->toBeIn(['http', 'queue', 'schedule', 'console']);
    expect($c->category)->toBe('runtime');
    expect($c->tags['gym'])->toBe('acme');
});
