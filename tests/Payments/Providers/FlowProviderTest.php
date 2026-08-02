<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Innertia\Facades\Settings;
use Innertia\Payments\Enums\ChargeStatus;
use Innertia\Payments\Events\PaymentFailed;
use Innertia\Payments\Events\PaymentPaid;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Providers\FlowProvider;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('services.flow.base_url', 'https://sandbox.flow.cl/api');
    Settings::set('payments.flow.api_key', 'flow-key');
    Settings::set('payments.flow.secret_key', 'flow-secret');
});

it('crea un link de pago firmado en Flow', function () {
    Http::fake([
        '*/payment/create' => Http::response(['url' => 'https://flow.cl/pay', 'token' => 'tok-123'], 200),
    ]);

    $charge = Charge::create([
        'amount' => 8000, 'currency' => 'CLP', 'status' => 'pending',
        'metadata' => ['concept' => 'Plan', 'email' => 'a@b.cl'],
    ]);

    $payment = (new FlowProvider)->createPayment($charge);

    expect($payment->external_reference)->toBe('tok-123')
        ->and($payment->link)->toContain('token=tok-123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/payment/create')
            && $request['apiKey'] === 'flow-key'
            && ! empty($request['s']); // firma presente
    });
});

it('confirma el charge cuando Flow reporta status 2', function () {
    Event::fake([PaymentPaid::class]);
    Http::fake([
        '*/payment/create'    => Http::response(['url' => 'https://flow.cl/pay', 'token' => 'tok-9'], 200),
        '*/payment/getStatus' => Http::response(['status' => 2], 200),
    ]);

    $charge  = Charge::create(['amount' => 5000, 'currency' => 'CLP', 'status' => 'pending', 'metadata' => ['email' => 'x@y.cl']]);
    $gateway = new FlowProvider;
    $gateway->createPayment($charge);

    $result = $gateway->handleWebhook(['token' => 'tok-9']);

    expect($result?->id)->toBe($charge->id)
        ->and($charge->fresh()->status)->toBe(ChargeStatus::Paid);
    Event::assertDispatched(PaymentPaid::class);
});

it('marca el charge como fallido cuando Flow reporta status 3', function () {
    Event::fake([PaymentFailed::class]);
    Http::fake([
        '*/payment/create'    => Http::response(['url' => 'https://flow.cl/pay', 'token' => 'tok-fail'], 200),
        '*/payment/getStatus' => Http::response(['status' => 3], 200),
    ]);

    $charge  = Charge::create(['amount' => 5000, 'currency' => 'CLP', 'status' => 'pending', 'metadata' => ['email' => 'x@y.cl']]);
    $gateway = new FlowProvider;
    $gateway->createPayment($charge);

    $gateway->handleWebhook(['token' => 'tok-fail']);

    expect($charge->fresh()->status)->toBe(ChargeStatus::Failed);
    Event::assertDispatched(PaymentFailed::class);
});
