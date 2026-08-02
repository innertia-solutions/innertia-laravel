<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\Settings;
use Innertia\Payments\Enums\ChargeStatus;
use Innertia\Payments\Events\PaymentPaid;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Providers\MercadoPagoProvider;

uses(RefreshDatabase::class);

it('sin credenciales usa el link mock (modo directo)', function () {
    $charge = Charge::create(['amount' => 6000, 'currency' => 'CLP', 'status' => 'pending']);

    $payment = (new MercadoPagoProvider)->createPayment($charge);

    expect($payment->provider)->toBe('mercadopago')
        ->and($payment->link)->toBe("/checkout/mock/{$charge->id}");
});

it('en split usa application_fee como marketplace_fee', function () {
    Settings::set('payments.collection_mode', 'split');
    Settings::set('payments.mercadopago.seller_access_token', 'TEST-seller');
    Http::fake([
        '*/checkout/preferences' => Http::response(['init_point' => 'https://mp/pay', 'sandbox_init_point' => 'https://mp/sandbox'], 200),
    ]);

    $charge = Charge::create(['amount' => 20000, 'currency' => 'CLP', 'status' => 'pending', 'application_fee' => 1800]);

    (new MercadoPagoProvider)->createPayment($charge);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/checkout/preferences')
        && (int) $request['marketplace_fee'] === 1800);
});

it('en split sin application_fee lanza ForbiddenException', function () {
    Settings::set('payments.collection_mode', 'split');
    Settings::set('payments.mercadopago.seller_access_token', 'TEST-seller');

    $charge = Charge::create(['amount' => 20000, 'currency' => 'CLP', 'status' => 'pending']); // sin application_fee

    expect(fn () => (new MercadoPagoProvider)->createPayment($charge))
        ->toThrow(ForbiddenException::class);
});

it('el webhook approved confirma el charge y emite PaymentPaid', function () {
    Event::fake([PaymentPaid::class]);
    $charge = Charge::create(['amount' => 6000, 'currency' => 'CLP', 'status' => 'pending']);
    (new MercadoPagoProvider)->createPayment($charge);

    $result = (new MercadoPagoProvider)->handleWebhook([
        'data' => ['status' => 'approved', 'external_reference' => $charge->id],
    ]);

    expect($result?->id)->toBe($charge->id)
        ->and($charge->fresh()->status)->toBe(ChargeStatus::Paid);
    Event::assertDispatched(PaymentPaid::class);
});

it('verifySignature devuelve true fuera de producción', function () {
    expect((new MercadoPagoProvider)->verifySignature('ts=1,v1=abc', 'req', 'data'))->toBeTrue();
});
