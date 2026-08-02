<?php

use Innertia\Payments\MercadoPagoOAuth;

it('firma y resuelve el state del tenant (round-trip)', function () {
    $oauth = new MercadoPagoOAuth;
    $state = $oauth->stateFor('acme');

    expect($oauth->tenantFromState($state))->toBe('acme');
});

it('lanza en un state inválido', function () {
    expect(fn () => (new MercadoPagoOAuth)->tenantFromState('basura'))
        ->toThrow(\RuntimeException::class);
});
