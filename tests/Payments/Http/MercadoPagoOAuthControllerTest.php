<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Innertia\Payments\Http\Controllers\MercadoPagoOAuthController;
use Innertia\Payments\MercadoPagoOAuth;

uses(RefreshDatabase::class);

it('connect devuelve la URL de autorización', function () {
    config()->set('services.mercadopago.app_id', 'app-123');
    config()->set('services.mercadopago.redirect_uri', 'https://x/callback');

    $response = (new MercadoPagoOAuthController)->connect();
    $data = $response->getData(true);

    expect($data['url'])->toContain('auth.mercadopago.cl/authorization')
        ->and($data['url'])->toContain('client_id=app-123');
});

it('callback intercambia el code y redirige', function () {
    config()->set('services.mercadopago.app_id', 'app-123');
    config()->set('services.mercadopago.client_secret', 'secret');
    config()->set('services.mercadopago.redirect_uri', 'https://x/callback');
    config()->set('services.mercadopago.connect_redirect', '/platform/integrations');

    Http::fake([
        '*/oauth/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'user_id' => 9, 'expires_in' => 3600], 200),
    ]);

    $state = (new MercadoPagoOAuth)->stateFor('acme');
    $request = Request::create('/x', 'GET', ['code' => 'the-code', 'state' => $state]);

    $response = (new MercadoPagoOAuthController)->callback($request);

    expect($response->getStatusCode())->toBe(302);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/oauth/token'));
});
