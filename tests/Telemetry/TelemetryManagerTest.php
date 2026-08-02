<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Telemetry\Models\TelemetryEvent;
use Innertia\Telemetry\TelemetryManager;

uses(RefreshDatabase::class);

it('graba un evento de log con el canal indicado', function () {
    app(TelemetryManager::class)->log('payments', 'info', 'charge paid', ['charge_id' => 42]);

    $event = TelemetryEvent::query()->where('type', 'log')->latest('id')->first();

    expect($event)->not->toBeNull()
        ->and($event->channel)->toBe('payments')
        ->and($event->payload['level'])->toBe('info')
        ->and($event->payload['message'])->toBe('charge paid')
        ->and($event->payload['context']['charge_id'])->toBe(42);
});

it('redacta llaves sensibles del context', function () {
    app(\Innertia\Telemetry\TelemetryManager::class)
        ->log('payments', 'info', 'charge', ['card' => '4111', 'password' => 'x', 'amount' => 100]);

    $event = \Innertia\Telemetry\Models\TelemetryEvent::query()->where('type', 'log')->latest('id')->first();

    expect($event->payload['context']['password'])->toBe('[REDACTED]')
        ->and($event->payload['context']['amount'])->toBe(100);
});

it('expone el facade Telemetry', function () {
    \Innertia\Facades\Telemetry::log('operational', 'warning', 'algo pasó');

    expect(TelemetryEvent::query()->where('channel', 'operational')->exists())->toBeTrue();
});
