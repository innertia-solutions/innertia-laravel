# Innertia\Payments

Motor de cobros genérico. La lib provee el ciclo del cobro, los proveedores
(MercadoPago/Flow/Transfer), webhooks y OAuth. El producto aporta el "para qué"
(`billable` morphTo), la comisión (`application_fee`) y el enablement por plan.

## Config de app (config/services.php del producto)

    'mercadopago' => [
        'app_id'           => env('MP_APP_ID'),
        'client_secret'    => env('MP_CLIENT_SECRET'),
        'redirect_uri'     => env('MP_REDIRECT_URI'),      // callback OAuth
        'webhook_secret'   => env('MP_WEBHOOK_SECRET'),    // firma de webhooks (prod)
        'connect_redirect' => env('MP_CONNECT_REDIRECT', '/platform/integrations'),
    ],
    'flow' => [
        'base_url' => env('FLOW_BASE_URL', 'https://sandbox.flow.cl/api'),
    ],

## Config por tenant (facade Settings, encriptado)

- `payments.collection_mode` — `direct` | `split`
- `payments.{transfer|mercadopago|flow}.enabled` — bool
- `payments.mercadopago.access_token` — token directo del tenant
- `payments.mercadopago.seller_access_token` / `seller_refresh_token` — poblados por el OAuth
- `payments.flow.api_key` / `payments.flow.secret_key`

## Rutas (el producto las registra)

Públicas (sin auth, verificadas por firma/consulta):

    Route::post('payments/webhook/mercadopago', [PaymentWebhookController::class, 'mercadopago']);
    Route::post('payments/webhook/flow',        [PaymentWebhookController::class, 'flow']);
    Route::get('oauth/mercadopago/callback',    [MercadoPagoOAuthController::class, 'callback']);

Autenticada (bajo el guard del producto):

    Route::get('payments/mercadopago/connect', [MercadoPagoOAuthController::class, 'connect']);

Controllers en `Innertia\Payments\Http\Controllers`.

## Uso

    $charge = Charge::create([...]);            // billable + application_fee los pone el producto
    $charge->billable()->associate($membership)->save();
    $payment = app(PaymentManager::class)->for(PaymentProvider::MercadoPago)->createPayment($charge);
    // ... el webhook confirma y dispara PaymentPaid($charge); el producto escucha.
