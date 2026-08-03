<?php
use Innertia\Telemetry\TelemetryTags;
use Innertia\Facades\Telemetry;
it('acumula tags custom vía el facade', function () {
    Telemetry::tag('gym', 'acme');
    Telemetry::tag('plan', 'pro');
    expect(app(TelemetryTags::class)->all())->toBe(['gym' => 'acme', 'plan' => 'pro']);
});
