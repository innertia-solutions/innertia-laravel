<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Support\Facades\DB;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\LineKind;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\Adjustment;
use Innertia\Billing\Models\Allocation;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\LateFee;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\Support\Money;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Refacturación: voids an issued invoice (mandatory reason) and issues its replacement
 * (replaces_invoice_id), with full trail.
 *
 *   - The statements move to the new invoice. Optional correction lines ({description, amount, tenant_id?},
 *     net, signed) are added to the space's statement (VAT recomputed).
 *   - Optional billing data corrections (legal_name, tax_id, …) on top of the voided snapshot, or
 *     refreshBillingData = take the current client/space data.
 *   - Payments and credit notes applied to the voided invoice move to the new one; what exceeds the new
 *     total goes back to the client as credit.
 *   - Due date: the original one unless $dueAt is given (a correction does not reset the late payment).
 *   - Bitácora: `voided` on the old one and `reissued` on the new one with who, when, why and the
 *     differences (billing data, totals, lines added).
 */
class ReissueInvoice extends UseCase
{
    public int $timeout = 120;

    /**
     * @param  array<string, ?string>|null  $billingData  fields to correct
     * @param  array<int, array{description: string, amount: int, tenant_id?: ?string}>  $lines
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $reason,
        public readonly ?array $billingData = null,
        public readonly array $lines = [],
        public readonly bool $refreshBillingData = false,
        public readonly ?\DateTimeInterface $dueAt = null,
        public readonly bool $send = true,
        public readonly ?string $folio = null,
    ) {}

    public function execute(): Invoice
    {
        BillingLog::ensureTrace();
        $old = $this->invoice->fresh(['statements.lines']);
        VoidInvoice::assertVoidable($old, $this->reason);
        $userId = auth()->check() ? (string) auth()->id() : null;

        $before = [
            'billing_data' => $old->billing_data ?? [],
            'subtotal' => $old->subtotal, 'tax' => $old->tax, 'total' => $old->total, 'balance' => $old->balance,
            'number' => $old->displayNumber(), 'folio' => $old->folio,
            'lines' => $old->statements->flatMap(fn ($s) => $s->lines->map(fn ($l) => [
                'tenant_id' => (string) $s->tenant_id, 'kind' => $l->kind->value, 'description' => $l->description, 'amount' => $l->amount,
            ]))->values()->all(),
        ];

        $billingData = $this->refreshBillingData
            ? ($old->tenant_id !== null ? $old->tenant?->billingData() : $old->client?->billingData())
            : ($old->billing_data ?? []);
        if ($this->billingData) {
            $billingData = array_merge($billingData ?? [], array_intersect_key($this->billingData, array_flip(\Innertia\Saas\Models\Client::BILLING_FIELDS)));
        }

        $new = DB::transaction(function () use ($old, $userId) {
            $new = Invoice::create([
                'client_id'           => $old->client_id,
                'tenant_id'           => $old->tenant_id,
                'period_start'        => $old->period_start->format('Y-m-d'),
                'period_end'          => $old->period_end->format('Y-m-d'),
                'currency'            => $old->currency,
                'status'              => InvoiceStatus::Draft,
                'payment_method'      => $old->payment_method,
                'replaces_invoice_id' => $old->id,
                'late_fees_waived_at' => $old->late_fees_waived_at,
                'trace_id'            => traceId() ?: null,
            ]);

            Statement::query()->where('invoice_id', $old->id)->update(['invoice_id' => $new->id]);
            $this->addCorrections($new);
            AssembleInvoice::recompute($new);

            // Applied money and the invoice's history follow the replacement.
            Allocation::query()->where('invoice_id', $old->id)->update(['invoice_id' => $new->id]);
            Adjustment::query()->where('invoice_id', $old->id)->update(['invoice_id' => $new->id]);
            LateFee::query()->where('invoice_id', $old->id)->update(['invoice_id' => $new->id]);
            $this->returnOverflow($new);

            $old->update([
                'status' => InvoiceStatus::Void, 'balance' => 0, 'void_reason' => $this->reason, 'voided_at' => now(),
                'voided_by' => $userId, 'replaced_by_invoice_id' => $new->id,
            ]);
            $old->log('voided', ['reason' => $this->reason, 'total' => $old->total, 'replaced_by' => $new->id], $userId);
            $new->log('created', ['replaces' => $old->id, 'replaces_number' => $old->displayNumber()], $userId);

            return $new;
        });

        $issued = (new IssueInvoice(
            $new, folio: $this->folio, pdfFileId: null, send: $this->send, billingData: $billingData,
            dueAt: $this->dueAt ?? $old->due_at,
        ))->execute();

        $diff = [
            'reason'       => $this->reason,
            'replaces'     => $old->displayNumber(),
            'billing_data' => self::changes($before['billing_data'], $issued->billing_data ?? []),
            'totals'       => [
                'before' => array_intersect_key($before, array_flip(['subtotal', 'tax', 'total'])),
                'after'  => ['subtotal' => $issued->subtotal, 'tax' => $issued->tax, 'total' => $issued->total],
            ],
            'lines_added'  => array_values($this->lines),
            'lines_before' => $before['lines'],
        ];
        $issued->log('reissued', $diff, $userId);

        (new RunDunning(clientId: $issued->client_id))->execute();
        Activity::record('invoice', $issued->id, 'reissued', $issued->client_id, $diff + ['number' => $issued->displayNumber()], $issued->tenant_id, $userId);
        BillingLog::info('invoice reissued', ['invoice_id' => $issued->id, 'replaces' => $old->id, 'total' => $issued->total]);

        return $issued->fresh();
    }

    private function addCorrections(Invoice $new): void
    {
        if ($this->lines === []) {
            return;
        }

        $statements = Statement::query()->where('invoice_id', $new->id)->orderByDesc('period_start')->get();
        if ($statements->isEmpty()) {
            throw new ConflictException('La factura no tiene cobros para corregir.');
        }

        foreach ($this->lines as $line) {
            $amount = (int) ($line['amount'] ?? 0);
            $description = trim((string) ($line['description'] ?? ''));
            if ($amount === 0 || $description === '') {
                throw new ConflictException('Cada corrección necesita descripción y un monto distinto de cero.');
            }
            $target = isset($line['tenant_id']) && $line['tenant_id'] !== null
                ? $statements->firstWhere('tenant_id', (string) $line['tenant_id'])
                : $statements->first();
            if (! $target) {
                throw new ConflictException('El espacio de la corrección no está en la factura.');
            }

            $target->lines()->create([
                'kind' => LineKind::Adjustment, 'description' => $description, 'quantity' => 1, 'unit_price' => $amount,
                'amount' => $amount, 'meta' => ['correction' => true, 'reason' => $this->reason],
                'position' => (int) $target->lines()->max('position') + 1,
            ]);
        }

        foreach ($statements as $statement) {
            $subtotal = max(0, (int) $statement->lines()->sum('amount'));
            $tax = Money::round($subtotal * (float) $statement->tax_rate / 100);
            $statement->update(['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax]);
        }
    }

    /** Allocations above the new total go back to their payments (newest first). */
    private function returnOverflow(Invoice $new): void
    {
        $overflow = $new->allocatedAmount() - $new->fresh()->total;
        foreach (Allocation::query()->where('invoice_id', $new->id)->orderByDesc('created_at')->get() as $allocation) {
            if ($overflow <= 0) {
                break;
            }
            $back = min($overflow, $allocation->amount);
            Payment::query()->whereKey($allocation->payment_id)->increment('unallocated_amount', $back);
            $allocation->amount - $back > 0 ? $allocation->update(['amount' => $allocation->amount - $back]) : $allocation->delete();
            $new->log('payment_released', ['payment_id' => $allocation->payment_id, 'amount' => $back]);
            $overflow -= $back;
        }
    }

    /** @return array<string, array{before: mixed, after: mixed}> */
    public static function changes(array $before, array $after): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $out[$key] = ['before' => $before[$key] ?? null, 'after' => $after[$key] ?? null];
            }
        }

        return $out;
    }
}
