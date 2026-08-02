<?php

namespace Innertia\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Facades\Innertia;
use Innertia\Payments\MercadoPagoOAuth;

/**
 * OAuth de MercadoPago Marketplace. `connect` (autenticado) devuelve la URL de
 * autorización para el tenant activo; `callback` (público) intercambia el code y guarda
 * los tokens del seller (tenant resuelto vía el state firmado). El producto registra rutas.
 */
class MercadoPagoOAuthController extends Controller
{
    public function connect(): JsonResponse
    {
        $tenantKey = Innertia::tenant()?->key ?? '';

        return response()->json([
            'url' => (new MercadoPagoOAuth)->authorizeUrl($tenantKey),
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $code  = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');

        if ($code !== '' && $state !== '') {
            (new MercadoPagoOAuth)->handleCallback($code, $state);
        }

        return redirect(config('services.mercadopago.connect_redirect', '/platform/integrations'));
    }
}
