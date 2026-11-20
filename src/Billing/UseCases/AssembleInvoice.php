<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\PaymentMethod;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Statement;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Saas\Enums\InvoicingMode;

/**
 * Puts a closed statement into a draft invoice according to the client's invoicing_mode:
 *   per_space    → one invoice per statement (tenant_id = the space)
 *   consolidated → the client's open draft (tenant_id null), created when none
 * Statements with total 0 are not invoiced. Totals are recomputed from the statements.
 */
class AssembleInvoice extends UseCase
{
    public function __construct(public readonly Statement $statement) {}

    public function execute(): ?Invoice
    {
        $statement = $this->statement;
        if ($statement->total <= 0 || $statement->invoice_id !== null) {
            return null;
        }

        $tenant = $statement->tenant;
        $client = $tenant?->client;
        if (! $client) {
            return null;
        }

        $consolidated = $client->invoicing_mode !== InvoicingMode::PerSpace;
        $invoice = $consolidated
            ? Invoice::query()->where('client_id', $client->id)->whereNull('tenant_id')->where('status', InvoiceStatus::Draft)->first()
            : null;

        $created = $invoice === null;
        $invoice ??= Invoice::create([
            'client_id'      => $client->id,
            'tenant_id'      => $consolidated ? null : (string) $statement->tenant_id,
            'period_start'   => $statement->period_start->format('Y-m-d'),
            'period_end'     => $statement->period_end->format('Y-m-d'),
            'currency'       => $statement->currency,
            'status'         => InvoiceStatus::Draft,
            'payment_method' => $statement->subscription?->payment_method ?? PaymentMethod::Invoice,
            'trace_id'       => traceId() ?: null,
        ]);

        $statement->update(['invoice_id' => $invoice->id]);
        self::recompute($invoice);

        $invoice->log($created ? 'created' : 'statement_added', ['statement_id' => $statement->id, 'tenant_id' => $statement->tenant_id]);

        return $invoice;
    }

    /** Sums the statements; period spans them; automatic only if every subscription is automatic. */
    public static function recompute(Invoice $invoice): Invoice
    {
        $statements = $invoice->statements()->with('subscription')->get();

        $total = (int) $statements->sum('total');
        $methods = $statements->map(fn ($s) => $s->subscription?->payment_method)->filter()->unique(fn ($m) => $m->value);

        $invoice->update([
            'subtotal'       => (int) $statements->sum('subtotal'),
            'tax'            => (int) $statements->sum('tax'),
            'total'          => $total,
            'balance'        => $total,
            'period_start'   => $statements->min(fn ($s) => $s->period_start->format('Y-m-d')) ?? $invoice->period_start,
            'period_end'     => $statements->max(fn ($s) => $s->period_end->format('Y-m-d')) ?? $invoice->period_end,
            'payment_method' => $methods->count() === 1 ? $methods->first() : PaymentMethod::Invoice,
        ]);

        return $invoice;
    }
}
