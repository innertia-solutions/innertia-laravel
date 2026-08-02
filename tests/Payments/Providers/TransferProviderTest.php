<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Innertia\Payments\Enums\ChargeStatus;
use Innertia\Payments\Events\PaymentPaid;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Providers\TransferProvider;

uses(RefreshDatabase::class);

it('declarar deja el charge en awaiting_confirmation', function () {
    $charge = Charge::create(['amount' => 10000, 'currency' => 'CLP', 'status' => 'pending']);

    $payment = (new TransferProvider)->createPayment($charge);

    expect($payment->provider)->toBe('transfer')
        ->and($payment->declared_at)->not->toBeNull()
        ->and($charge->fresh()->status)->toBe(ChargeStatus::AwaitingConfirmation);
});

it('confirmar deja el charge pagado y emite PaymentPaid (idempotente)', function () {
    Event::fake([PaymentPaid::class]);
    $charge  = Charge::create(['amount' => 10000, 'currency' => 'CLP', 'status' => 'pending']);
    $gateway = new TransferProvider;
    $payment = $gateway->createPayment($charge);

    $gateway->confirm($payment);
    $gateway->confirm($payment->fresh()); // segunda vez: no re-transiciona

    expect($charge->fresh()->status)->toBe(ChargeStatus::Paid);
    Event::assertDispatchedTimes(PaymentPaid::class, 1);
});

it('deriva commission_rate desde application_fee', function () {
    $charge = Charge::create(['amount' => 10000, 'currency' => 'CLP', 'status' => 'pending', 'application_fee' => 500]);

    $payment = (new TransferProvider)->createPayment($charge);

    expect((float) $payment->commission_rate)->toBe(0.05); // 500/10000
});
