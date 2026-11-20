<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Support\Facades\DB;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\LateFee;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Models\StatementLine;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\Support\Money;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Condona el interés por mora de una factura vencida (reason mandatory, bitácora `late_fee_waived`):
 *   - stops future interest for that invoice (late_fees_waived_at);
 *   - interest already in a draft charge is removed from it (statement and draft invoice recomputed);
 *   - interest already in an issued invoice is returned with a credit note (to that invoice while it
 *     is open, otherwise as client credit).
 */
class WaiveLateFees extends UseCase
{
    public function __construct(public readonly Invoice $invoice, public readonly string $reason) {}

    /** @return array{removed: int, credited: int} */
    public function execute(): array
    {
        BillingLog::ensureTrace();
        $invoice = $this->invoice->fresh();
        if (trim($this->reason) === '') {
            throw new ConflictException('El motivo es obligatorio.');
        }
        if ($invoice->late_fees_waived_at !== null) {
            throw new ConflictException('El interés de esta factura ya fue condonado.');
        }
        $userId = auth()->check() ? (string) auth()->id() : null;

        $removed = 0;
        $toCredit = [];   // billed invoice id => amount with VAT

        DB::transaction(function () use ($invoice, $userId, &$removed, &$toCredit) {
            $invoice->update(['late_fees_waived_at' => now()]);

            foreach (LateFee::query()->where('invoice_id', $invoice->id)->whereNull('waived_at')->get() as $fee) {
                $fee->update(['waived_at' => now(), 'waived_by' => $userId, 'waive_reason' => $this->reason]);
                $statement = $fee->statement_id ? Statement::query()->find($fee->statement_id) : null;
                if (! $statement) {
                    continue;
                }

                $billedIn = $statement->invoice_id ? Invoice::query()->find($statement->invoice_id) : null;
                if ($billedIn === null || $billedIn->status === InvoiceStatus::Draft) {
                    // Still a draft: drop the line and recompute.
                    StatementLine::query()->where('statement_id', $statement->id)->where('kind', 'late_fee')
                        ->get()->filter(fn ($l) => ($l->meta['invoice_id'] ?? null) === $invoice->id && ($l->meta['from'] ?? null) === $fee->from_date->format('Y-m-d'))
                        ->each->delete();
                    $subtotal = max(0, (int) $statement->lines()->sum('amount'));
                    $tax = Money::round($subtotal * (float) $statement->tax_rate / 100);
                    $statement->update(['subtotal' => $subtotal, 'tax' => $tax, 'total' => $subtotal + $tax]);
                    if ($billedIn) {
                        AssembleInvoice::recompute($billedIn);
                    }
                    $removed += $fee->amount;
                } elseif ($billedIn->status !== InvoiceStatus::Void) {
                    $toCredit[$billedIn->id] = ($toCredit[$billedIn->id] ?? 0) + Money::round($fee->amount * (1 + (float) $statement->tax_rate / 100));
                }
            }
        });

        $credited = 0;
        foreach ($toCredit as $billedId => $amount) {
            $billed = Invoice::query()->find($billedId);
            $open = $billed->status->isOpen() && $billed->balance > 0;
            (new CreateCreditNote(
                clientId: $billed->client_id, amount: $amount,
                reason: "Condonación de interés por mora factura {$invoice->displayNumber()}: {$this->reason}",
                target: $open ? 'invoice' : 'balance', invoiceId: $open ? $billed->id : null, tenantId: $billed->tenant_id,
            ))->execute();
            $credited += $amount;
        }

        $invoice->log('late_fee_waived', ['reason' => $this->reason, 'removed' => $removed, 'credited' => $credited], $userId);
        Activity::record('late_fee', $invoice->id, 'waived', $invoice->client_id, ['invoice' => $invoice->displayNumber(), 'reason' => $this->reason, 'removed' => $removed, 'credited' => $credited], $invoice->tenant_id, $userId);
        BillingLog::info('late fees waived', ['invoice_id' => $invoice->id, 'removed' => $removed, 'credited' => $credited]);

        return ['removed' => $removed, 'credited' => $credited];
    }
}
