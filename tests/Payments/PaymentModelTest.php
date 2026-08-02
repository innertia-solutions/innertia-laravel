<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Payments\Models\Charge;
use Innertia\Payments\Models\Payment;

uses(RefreshDatabase::class);

it('persiste un payment ligado a un charge', function () {
    $charge = Charge::create(['amount' => 5000, 'currency' => 'CLP', 'status' => 'pending']);

    $payment = Payment::create([
        'charge_id'          => $charge->id,
        'provider'           => 'mercadopago',
        'amount'             => 5000,
        'external_reference' => 'ext-1',
        'collection_mode'    => 'direct',
    ]);

    expect($payment->charge->id)->toBe($charge->id)
        ->and($charge->payments()->count())->toBe(1);
});
