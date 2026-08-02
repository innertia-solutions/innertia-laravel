<?php

use Innertia\Telemetry\Http\Controllers\ObservabilityChannelsController;
use Innertia\Telemetry\Http\Controllers\ObservabilityDatabaseController;

it('el snapshot de BD compone capacidad + disco + cola', function () {
    $data = (new ObservabilityDatabaseController())->show()->getData(true);

    expect($data)->toHaveKeys(['capacity', 'disk', 'queue']);
});

it('lista los canales configurados', function () {
    config()->set('innertia.telemetry.channels', [
        ['key' => 'operational', 'label' => 'Logs operativos', 'icon' => 'IconFileText'],
        ['key' => 'payments', 'label' => 'Logs de pagos', 'icon' => 'IconCreditCard'],
    ]);

    $data = (new ObservabilityChannelsController())->index()->getData(true);

    expect(collect($data['channels'])->pluck('key')->all())->toBe(['operational', 'payments']);
});
