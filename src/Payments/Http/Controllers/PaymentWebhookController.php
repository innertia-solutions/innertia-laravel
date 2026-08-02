<?php

namespace Innertia\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Payments\Enums\PaymentProvider;
use Innertia\Payments\PaymentManager;
use Innertia\Payments\Providers\MercadoPagoProvider;

/**
 * Webhooks de pago. Controllers finos: verifican firma (cuando aplica) y delegan en
 * el proveedor. NO loguean telemetría — eso es del producto (listener de PaymentPaid).
 */
class PaymentWebhookController extends Controller
{
    public function mercadopago(Request $request): JsonResponse
    {
        $provider = new MercadoPagoProvider;

        $dataId = (string) ($request->query('data.id')
            ?? $request->query('id')
            ?? data_get($request->all(), 'data.id', ''));

        $valid = $provider->verifySignature(
            signatureHeader: (string) $request->header('x-signature', ''),
            requestId: (string) $request->header('x-request-id', ''),
            dataId: $dataId,
        );

        if (! $valid) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $provider->handleWebhook($request->all());

        return response()->json(['received' => true]);
    }

    public function flow(Request $request): JsonResponse
    {
        (new PaymentManager)->for(PaymentProvider::Flow)->handleWebhook($request->all());

        return response()->json(['received' => true]);
    }
}
