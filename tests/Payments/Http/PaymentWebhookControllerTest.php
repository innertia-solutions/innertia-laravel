<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Innertia\Payments\Enums\ChargeStatus;
use Innertia\Payments\Http\Controllers\PaymentWebhookController;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Providers\MercadoPagoProvider;

uses(RefreshDatabase::class);

it('procesa el webhook approved de MercadoPago y responde 200', function () {
    $charge = Charge::create(['amount' => 6000, 'currency' => 'CLP', 'status' => 'pending']);
    (new MercadoPagoProvider)->createPayment($charge);

    $request = Request::create('/x', 'POST', ['data' => ['status' => 'approved', 'external_reference' => $charge->id]]);
    $response = (new PaymentWebhookController)->mercadopago($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($charge->fresh()->status)->toBe(ChargeStatus::Paid);
});

it('rechaza el webhook de MercadoPago con firma inválida en producción (401)', function () {
    App::detectEnvironment(fn () => 'production');
    config()->set('services.mercadopago.webhook_secret', 'shh');

    $request = Request::create('/x', 'POST', ['data' => ['id' => '123']]);
    $request->headers->set('x-signature', 'ts=1,v1=deadbeef');
    $request->headers->set('x-request-id', 'req-1');

    $response = (new PaymentWebhookController)->mercadopago($request);

    expect($response->getStatusCode())->toBe(401);
});
