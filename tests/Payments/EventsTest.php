<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Payments\Events\PaymentFailed;
use Innertia\Payments\Events\PaymentPaid;
use Innertia\Payments\Models\Charge;

uses(RefreshDatabase::class);

it('los eventos transportan el charge', function () {
    $charge = Charge::create(['amount' => 3000, 'currency' => 'CLP', 'status' => 'pending']);

    $paid   = new PaymentPaid($charge);
    $failed = new PaymentFailed($charge);

    expect($paid->charge->id)->toBe($charge->id)
        ->and($failed->charge->id)->toBe($charge->id);
});
