<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\Settings;
use Innertia\Payments\Enums\PaymentProvider;
use Innertia\Payments\PaymentManager;
use Innertia\Payments\Providers\FlowProvider;
use Innertia\Payments\Providers\MercadoPagoProvider;

uses(RefreshDatabase::class);

it('resuelve el gateway correcto por proveedor', function () {
    expect((new PaymentManager)->for(PaymentProvider::Flow))->toBeInstanceOf(FlowProvider::class)
        ->and((new PaymentManager)->for(PaymentProvider::MercadoPago))->toBeInstanceOf(MercadoPagoProvider::class);
});

it('en split rechaza un proveedor que no lo soporta', function () {
    Settings::set('payments.collection_mode', 'split');

    expect(fn () => (new PaymentManager)->for(PaymentProvider::Flow))
        ->toThrow(ForbiddenException::class);
});

it('en split permite MercadoPago', function () {
    Settings::set('payments.collection_mode', 'split');

    expect((new PaymentManager)->for(PaymentProvider::MercadoPago))
        ->toBeInstanceOf(MercadoPagoProvider::class);
});
