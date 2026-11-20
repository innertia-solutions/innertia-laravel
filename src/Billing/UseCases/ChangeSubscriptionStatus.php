<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Support\SubscriptionState;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Manual actions from platform: suspend, reactivate, cancel.
 * Reactivating returns to past_due when the space still has overdue invoices (the next dunning
 * run keeps it there; it can be suspended again only by hand or past the grace days).
 */
class ChangeSubscriptionStatus extends UseCase
{
    public function __construct(public readonly Subscription $subscription, public readonly string $action) {}

    public function execute(): Subscription
    {
        $s = $this->subscription;

        match ($this->action) {
            'suspend'    => SubscriptionState::to($s, SubscriptionStatus::Suspended, 'manual', manual: true),
            'reactivate' => SubscriptionState::to($s, $this->hasOverdue($s) ? SubscriptionStatus::PastDue : SubscriptionStatus::Active, 'manual')
                || $s->update(['suspended_manually' => false]),
            'cancel'     => SubscriptionState::to($s, SubscriptionStatus::Canceled, 'manual', manual: true),
            default      => throw new ConflictException("Acción desconocida: {$this->action}."),
        };

        return $s->fresh(['plan']);
    }

    private function hasOverdue(Subscription $s): bool
    {
        $clientId = $s->tenant?->client_id;

        return \Innertia\Billing\Models\Invoice::query()
            ->where('status', InvoiceStatus::Overdue)
            ->where(fn ($q) => $q->where('tenant_id', $s->tenant_id)
                ->orWhere(fn ($w) => $w->whereNull('tenant_id')->where('client_id', $clientId)
                    ->whereHas('statements', fn ($st) => $st->where('tenant_id', $s->tenant_id))))
            ->exists();
    }
}
