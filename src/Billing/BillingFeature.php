<?php

namespace Innertia\Billing;

use Innertia\Saas\Clients\ClientsFeature;

/**
 * Platform billing to its clients. Opt-in: config('innertia.billing.enabled') and it needs the
 * clients feature (saas/open modes). Off → no tables, no schedule, no listeners, no routes.
 */
final class BillingFeature
{
    public static function isActive(): bool
    {
        return (bool) config('innertia.billing.enabled', false) && ClientsFeature::isActive();
    }
}
