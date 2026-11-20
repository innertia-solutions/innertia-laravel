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
            'voided_at'      => $i->voided_at?->toIso8601String(),
            'void_reason'    => $i->void_reason,
            'replaces_invoice_id'    => $i->replaces_invoice_id,
            'replaced_by_invoice_id' => $i->replaced_by_invoice_id,
            'late_fees_waived_at'    => $i->late_fees_waived_at?->toIso8601String(),
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
            $data['late_fees'] = \Innertia\Billing\Models\LateFee::query()->where('invoice_id', $i->id)->orderBy('from_date')->get()->map(fn ($f) => [
                'tenant_id' => $f->tenant_id, 'from' => $f->from_date->format('Y-m-d'), 'to' => $f->to_date->format('Y-m-d'), 'days' => $f->days,
                'base' => $f->base, 'rate' => $f->rate, 'amount' => $f->amount, 'statement_id' => $f->statement_id,
                'waived_at' => $f->waived_at?->toIso8601String(), 'waive_reason' => $f->waive_reason,
            ])->values()->all();
            $data['credit_notes'] = \Innertia\Billing\Models\Adjustment::query()->where('invoice_id', $i->id)->whereNotNull('number')->get()
                ->map(fn ($a) => self::adjustment($a))->values()->all();
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

    public static function adjustment(\Innertia\Billing\Models\Adjustment $a): array
    {
        return [
            'id'             => $a->id,
            'number'         => $a->number,
            'display_number' => $a->displayNumber(),
            'credit_note'    => $a->isCreditNote(),
            'tenant_id'      => $a->tenant_id,
            'client_id'      => $a->client_id,
            'scope'          => $a->tenant_id === null ? 'client' : 'space',
            'type'           => $a->type->value,
            'amount'         => $a->amount,
            'percent'        => $a->percent,
            'recurring'      => $a->recurring,
            'starts_at'      => $a->starts_at?->format('Y-m-d'),
            'ends_at'        => $a->ends_at?->format('Y-m-d'),
            'reason'         => $a->reason,
            'target'         => $a->target,
            'invoice_id'     => $a->invoice_id,
            'payment_id'     => $a->payment_id,
            'created_by'     => $a->created_by,
            'created_at'     => $a->created_at?->toIso8601String(),
            'consumed_at'    => $a->consumed_at?->toIso8601String(),
            'canceled_at'    => $a->canceled_at?->toIso8601String(),
            'canceled_by'    => $a->canceled_by,
            'cancel_reason'  => $a->cancel_reason,
            'status'         => $a->canceled_at ? 'canceled' : ($a->consumed_at ? 'applied' : 'pending'),
        ];
    }

    public static function bankImport(\Innertia\Billing\Models\BankImport $i): array
    {
        return [
            'id' => $i->id, 'file_name' => $i->file_name, 'format' => $i->format, 'bank' => $i->bank, 'mapping' => $i->mapping,
            'options' => $i->options ?? (object) [], 'status' => $i->status, 'rows_total' => $i->rows_total,
            'rows_imported' => $i->rows_imported, 'rows_duplicated' => $i->rows_duplicated, 'rows_skipped' => $i->rows_skipped,
            'matched' => $i->matched, 'suggested' => $i->suggested, 'error' => $i->error, 'created_by' => $i->created_by,
            'created_at' => $i->created_at?->toIso8601String(), 'processed_at' => $i->processed_at?->toIso8601String(),
        ];
    }

    public static function bankTransaction(\Innertia\Billing\Models\BankTransaction $t): array
    {
        return [
            'id' => $t->id, 'import_id' => $t->import_id, 'posted_at' => $t->posted_at->format('Y-m-d'), 'amount' => $t->amount,
            'description' => $t->description, 'reference' => $t->reference, 'tax_id' => $t->tax_id, 'counterpart_name' => $t->counterpart_name,
            'status' => $t->status, 'score' => $t->score, 'suggestions' => $t->suggestions ?? [], 'client_id' => $t->client_id,
            'client_name' => $t->client_id ? $t->client?->name : null, 'payment_id' => $t->payment_id, 'matched_by' => $t->matched_by,
            'matched_at' => $t->matched_at?->toIso8601String(), 'ignore_reason' => $t->ignore_reason,
        ];
    }

    public static function activity(\Innertia\Billing\Models\Activity $a): array
    {
        return [
            'id' => $a->id, 'client_id' => $a->client_id, 'tenant_id' => $a->tenant_id, 'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id, 'action' => $a->action, 'user_id' => $a->user_id, 'data' => $a->data ?? (object) [],
            'trace_id' => $a->trace_id, 'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
