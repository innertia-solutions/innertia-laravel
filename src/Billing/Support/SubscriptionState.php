<?php

namespace Innertia\Billing\Support;

use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Events\SubscriptionStatusChanged;
use Innertia\Billing\Models\Subscription;

/**
 * Subscription state machine.
 *   trialing → active (trial ends at the close) · active ⇄ past_due (overdue invoices)
 *   past_due → suspended (overdue beyond grace_days, or by hand) · suspended/past_due → active (no overdue left)
 *   any → canceled (by hand; final). Every change is logged (trace_id), fires SubscriptionStatusChanged
 *   and notices the space admins.
 */
final class SubscriptionState
{
    private const ALLOWED = [
        'trialing'  => ['active', 'suspended', 'canceled', 'past_due'],
        'active'    => ['past_due', 'suspended', 'canceled'],
        'past_due'  => ['active', 'suspended', 'canceled'],
        'suspended' => ['active', 'past_due', 'canceled'],
        'canceled'  => [],
    ];

    public static function canTransition(SubscriptionStatus $from, SubscriptionStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value], true);
    }

    public static function to(Subscription $subscription, SubscriptionStatus $to, string $reason, bool $manual = false): bool
    {
        $from = $subscription->status;
        if ($from === $to) {
            return false;
        }
        if (! self::canTransition($from, $to)) {
            throw new \DomainException("Transición inválida de la suscripción: {$from->value} → {$to->value}.");
        }

        $subscription->update([
            'status'             => $to,
            'suspended_at'       => $to === SubscriptionStatus::Suspended ? now() : null,
            'suspended_manually' => $to === SubscriptionStatus::Suspended && $manual,
            'canceled_at'        => $to === SubscriptionStatus::Canceled ? now() : $subscription->canceled_at,
        ]);

        BillingLog::info('subscription status changed', [
            'subscription_id' => $subscription->id, 'tenant_id' => $subscription->tenant_id,
            'from' => $from->value, 'to' => $to->value, 'reason' => $reason, 'manual' => $manual,
        ]);

        event(new SubscriptionStatusChanged($subscription, $from, $to, $reason));

        $notice = match ($to) {
            SubscriptionStatus::PastDue   => BillingNotices::PAST_DUE,
            SubscriptionStatus::Suspended => BillingNotices::SUSPENDED,
            SubscriptionStatus::Active    => in_array($from, [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended], true) ? BillingNotices::REACTIVATED : null,
            default                       => null,
        };
        if ($notice !== null) {
            BillingNotices::subscription($subscription, $notice);
        }

        return true;
    }
}
