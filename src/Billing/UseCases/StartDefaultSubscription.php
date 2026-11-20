<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\BillingFeature;
use Innertia\Billing\Enums\PaymentMethod;
use Innertia\Billing\Models\Plan;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Support\BillingLog;

/**
 * Self sign-up (CreateOpenTenant): with billing active and innertia.billing.default_plan set,
 * the new production space starts a subscription (trial = the plan's trial days). Never breaks
 * the sign-up: any failure is logged.
 */
final class StartDefaultSubscription
{
    public static function for(object $tenant): ?Subscription
    {
        $planKey = config('innertia.billing.default_plan');
        if (! BillingFeature::isActive() || blank($planKey) || (method_exists($tenant, 'isTesting') && $tenant->isTesting())) {
            return null;
        }

        try {
            $plan = Plan::query()->where('key', $planKey)->where('active', true)->first();
            if (! $plan) {
                BillingLog::warning('default plan not found', ['plan' => $planKey]);

                return null;
            }

            return (new StartSubscription($tenant->key, $plan->id, PaymentMethod::Automatic))->execute();
        } catch (\Throwable $e) {
            report($e);
            BillingLog::error('default subscription failed', ['tenant_id' => $tenant->getKey(), 'error' => $e->getMessage()]);

            return null;
        }
    }
}
