<?php

namespace Innertia\Billing\Http;

use Innertia\Billing\Meters;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Models\Plan;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Models\Subscription;

/** JSON shapes shared by the platform and backoffice endpoints. */
final class Present
{
    public static function plan(Plan $plan): array
    {
        $plan->loadMissing('meters');

        return [
            'id'             => $plan->id,
            'key'            => $plan->key,
            'name'           => $plan->name,
            'description'    => $plan->description,
            'product'        => $plan->product,
            'currency'       => $plan->currency,
            'interval'       => $plan->interval->value,
            'base_price'     => $plan->base_price,
            'included_seats' => $plan->included_seats,
            'seat_price'     => $plan->seat_price,
            'trial_days'     => $plan->trial_days,
            'tax_rate'       => $plan->tax_rate,
            'limits'         => $plan->limits ?? [],
            'active'         => $plan->active,
            'meters'         => $plan->meters->map(fn ($m) => [
                'meter'             => $m->meter,
                'label'             => Meters::get($m->meter)?->label ?? $m->meter,
                'unit'              => Meters::get($m->meter)?->unit,
                'valued_by_cost'    => (bool) Meters::get($m->meter)?->valuedByCost,
                'included_quantity' => $m->included_quantity,
                'unit_price'        => $m->unit_price,
                'markup_percent'    => $m->markup_percent,
            ])->values()->all(),
        ];
    }

    public static function subscription(?Subscription $s): ?array
    {
        if ($s === null) {
            return null;
        }

        $s->loadMissing(['plan', 'pendingPlan']);

        return [
            'id'                   => $s->id,
            'tenant_id'            => (string) $s->tenant_id,
            'status'               => $s->status->value,
            'status_label'         => $s->status->label(),
            'plan'                 => $s->plan ? ['id' => $s->plan->id, 'key' => $s->plan->key, 'name' => $s->plan->name] : null,
            'pending_plan'         => $s->pendingPlan ? ['id' => $s->pendingPlan->id, 'key' => $s->pendingPlan->key, 'name' => $s->pendingPlan->name] : null,
            'payment_method'       => $s->payment_method->value,
            'overrides'            => $s->overrides ?? (object) [],
            'started_at'           => $s->started_at?->format('Y-m-d'),
            'trial_ends_at'        => $s->trial_ends_at?->format('Y-m-d'),
            'current_period_start' => $s->current_period_start->format('Y-m-d'),
            'current_period_end'   => $s->current_period_end->format('Y-m-d'),
            'suspended_manually'   => $s->suspended_manually,
            'suspended_at'         => $s->suspended_at?->toIso8601String(),
            'canceled_at'          => $s->canceled_at?->toIso8601String(),
        ];
    }

    public static function invoice(Invoice $i, bool $detail = false): array
    {
        $data = [
            'id'             => $i->id,
            'number'         => $i->number,
            'display_number' => $i->displayNumber(),
            'client_id'      => $i->client_id,
            'client_name'    => $i->client?->name,
            'tenant_id'      => $i->tenant_id,
            'tenant_name'    => $i->tenant_id ? $i->tenant?->name : null,
            'consolidated'   => $i->isConsolidated(),
            'period_start'   => $i->period_start->format('Y-m-d'),
            'period_end'     => $i->period_end->format('Y-m-d'),
            'currency'       => $i->currency,
            'subtotal'       => $i->subtotal,
            'tax'            => $i->tax,
            'total'          => $i->total,
            'balance'        => $i->balance,
            'status'         => $i->status->value,
            'status_label'   => $i->status->label(),
            'payment_method' => $i->payment_method->value,
            'payment_link'   => $i->balance > 0 && $i->status->isOpen() ? $i->payment_link : null,
            'folio'          => $i->folio,
            'pdf_file_id'    => $i->pdf_file_id,
            'issued_at'      => $i->issued_at?->toIso8601String(),
            'due_at'         => $i->due_at?->toIso8601String(),
            'paid_at'        => $i->paid_at?->toIso8601String(),
            'sent_at'        => $i->sent_at?->toIso8601String(),
        ];

        if ($detail) {
            $i->loadMissing(['statements.lines', 'statements.tenant', 'events', 'allocations']);
            $data['billing_data'] = $i->billing_data;
            $data['statements'] = $i->statements->map(fn (Statement $s) => self::statement($s))->values()->all();
            $data['events'] = $i->events->map(fn ($e) => [
                'event' => $e->event, 'user_id' => $e->user_id, 'data' => $e->data, 'created_at' => $e->created_at?->toIso8601String(),
            ])->values()->all();
            $data['allocations'] = $i->allocations->map(fn ($a) => [
                'payment_id' => $a->payment_id, 'amount' => $a->amount, 'created_at' => $a->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }

    public static function statement(Statement $s): array
    {
        $s->loadMissing(['lines', 'tenant']);

        return [
            'id'           => $s->id,
            'tenant_id'    => (string) $s->tenant_id,
            'tenant_name'  => $s->tenant?->name,
            'invoice_id'   => $s->invoice_id,
            'period_start' => $s->period_start->format('Y-m-d'),
            'period_end'   => $s->period_end->format('Y-m-d'),
            'subtotal'     => $s->subtotal,
            'tax'          => $s->tax,
            'total'        => $s->total,
            'lines'        => $s->lines->map(fn ($l) => [
                'kind' => $l->kind->value, 'description' => $l->description, 'meter' => $l->meter,
                'quantity' => $l->quantity, 'unit_price' => $l->unit_price, 'amount' => $l->amount, 'meta' => $l->meta,
            ])->values()->all(),
        ];
    }

    public static function payment(Payment $p): array
    {
        $p->loadMissing('allocations');

        return [
            'id'                 => $p->id,
            'client_id'          => $p->client_id,
            'amount'             => $p->amount,
            'method'             => $p->method->value,
            'reference'          => $p->reference,
            'received_at'        => $p->received_at?->toIso8601String(),
            'unallocated_amount' => $p->unallocated_amount,
            'allocations'        => $p->allocations->map(fn ($a) => ['invoice_id' => $a->invoice_id, 'amount' => $a->amount])->values()->all(),
        ];
    }
}
