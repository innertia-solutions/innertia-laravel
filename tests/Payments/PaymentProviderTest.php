<?php

use Innertia\Payments\Enums\PaymentProvider;

it('declara capacidades por proveedor', function () {
    expect(PaymentProvider::MercadoPago->supports('split'))->toBeTrue()
        ->and(PaymentProvider::Flow->supports('split'))->toBeFalse()
        ->and(PaymentProvider::Transfer->supports('one_off'))->toBeTrue();
});
