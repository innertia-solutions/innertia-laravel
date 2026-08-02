<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Payments\Events\PaymentFailed;
use Innertia\Payments\Events\PaymentPaid;
use Innertia\Payments\Models\Charge;

uses(RefreshDatabase::class);

it('los eventos transportan el charge y opcionalmente el payment', function () {
    $charge  = Charge::create(['amount' => 3000, 'currency' => 'CLP', 'status' => 'pending']);
    $payment = \Innertia\Payments\Models\Payment::create(['charge_id' => $charge->id, 'provider' => 'transfer', 'amount' => 3000]);

    $paid = new \Innertia\Payments\Events\PaymentPaid($charge, $payment);
    expect($paid->charge->id)->toBe($charge->id)
        ->and($paid->payment?->id)->toBe($payment->id);

    $failed = new \Innertia\Payments\Events\PaymentFailed($charge);
    expect($failed->payment)->toBeNull();
});
