<?php

namespace Innertia\Billing;

use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Models\UsageRecord;
use Innertia\Facades\Innertia;

/**
 * Entry point of Innertia\Billing for products.
 *
 *   Billing::record('whatsapp.messages', 1, "wa:{$messageId}");          // active tenant
 *   Billing::subscription($tenant)?->status;                               // SubscriptionStatus|null
 *   Billing::isSuspended($tenant);                                         // read-only space
 *   Billing::inTenant($tenantId, fn () => …);                              // run with a tenant active
 *
 * Every call is safe with the feature off (record → null, subscription → null).
 */
final class Billing
{
    public const PERMISSION = 'billing.view';

    public static function enabled(): bool
    {
        return BillingFeature::isActive();
    }

    /** Spec API: records a meter for the ACTIVE tenant, idempotent by $sourceRef. */
    public static function record(string $meter, float $quantity, ?string $sourceRef = null, ?int $cost = null, array $meta = [], \DateTimeInterface|string|null $at = null): ?UsageRecord
    {
        return Meters::record(null, $meter, $quantity, $at, $meta, $sourceRef, $cost);
    }

    public static function subscription(mixed $tenant = null): ?Subscription
    {
        if (! self::enabled()) {
            return null;
        }

        $tenantId = Meters::tenantId($tenant);

        return $tenantId === null ? null : Subscription::query()->where('tenant_id', $tenantId)->first();
    }

    public static function isSuspended(mixed $tenant = null): bool
    {
        return self::subscription($tenant)?->status === SubscriptionStatus::Suspended;
    }

    /** Runs $callback with the tenant (by id) active, restoring the previous context. */
    public static function inTenant(?string $tenantId, callable $callback): mixed
    {
        if ($tenantId === null || ! Innertia::tenancyEnabled()) {
            return $callback();
        }

        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;
        $tenant = $model::query()->find($tenantId);
        if (! $tenant) {
            return $callback();
        }

        $context = app(\Innertia\Saas\TenantContext::class);
        $previous = $context->get();
        $context->set($tenant);

        try {
            return $callback();
        } finally {
            $previous ? $context->set($previous) : $context->clear();
        }
    }
}
