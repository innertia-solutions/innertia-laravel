<?php

namespace Innertia\Billing\Middleware;

use Closure;
use Illuminate\Http\Request;
use Innertia\Billing\Billing;
use Innertia\Billing\Enums\SubscriptionStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `billing.enforce`. Mount it in the backoffice (tenant) group, never in platform.
 *   suspended → read-only: writes answer 402 {code: subscription_suspended}; reads, login,
 *               billing and payment routes (innertia.billing.suspended_allow) keep working.
 *   past_due  → passes, with header X-Billing-Status: past_due (the front shows the banner).
 * Spaces without subscription, testing spaces and the feature off are never blocked. It does not
 * touch tenants.status: a space set `inactive` by hand stays governed by the product's own check.
 */
class EnforceSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $subscription = Billing::enabled() ? Billing::subscription() : null;

        if ($subscription?->status === SubscriptionStatus::Suspended
            && ! $request->isMethodSafe()
            && ! $request->is(...(array) config('innertia.billing.suspended_allow', []))) {
            return response()->json([
                'message' => 'El espacio está suspendido por facturas vencidas: solo lectura. Regulariza el pago desde Facturación.',
                'code'    => 'subscription_suspended',
            ], 402);
        }

        $response = $next($request);

        if ($subscription && in_array($subscription->status, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true)) {
            $response->headers->set('X-Billing-Status', $subscription->status->value);
        }

        return $response;
    }
}
