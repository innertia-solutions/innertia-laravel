<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Innertia\Facades\Telemetry;
use Innertia\Telemetry\Http\Controllers\ObservabilityLogsController;

uses(RefreshDatabase::class);

it('lista solo los logs del canal pedido', function () {
    Telemetry::log('operational', 'info', 'op-uno');
    Telemetry::log('payments', 'info', 'pay-uno');

    $request = Request::create('/x', 'GET', ['channel' => 'payments']);
    $response = (new ObservabilityLogsController())->index($request);

    $data = $response->getData(true);
    $rows = $data['data'] ?? $data['rows'] ?? $data;

    expect(collect($rows)->pluck('channel')->unique()->values()->all())->toBe(['payments']);
});
