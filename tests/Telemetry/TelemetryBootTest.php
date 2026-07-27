<?php

use Innertia\Telemetry\TelemetryServiceProvider;

it('registers TelemetryServiceProvider from the core provider without Olimpo', function () {
    // El TestCase base (tests/Pest.php → BaseTestCase::getPackageProviders)
    // registra SOLO InnertiaServiceProvider — OlimpoServiceProvider está
    // deliberadamente ausente. Si la telemetría se registra desde el core, su
    // provider queda cargado sin que Olimpo intervenga.
    expect(app()->getLoadedProviders())
        ->toHaveKey(TelemetryServiceProvider::class);
});
