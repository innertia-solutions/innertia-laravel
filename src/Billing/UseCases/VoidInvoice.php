<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Support\Facades\DB;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Support\Allocations;
use Innertia\Billing\Support\BillingLog;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Voids an issued invoice (issued, overdue or paid) with a mandatory reason. An issued invoice is never
 * edited: to correct it use ReissueInvoice. Payments and credit notes allocated to it go back to the
 * client as credit (saldo a favor). The bitácora keeps who, when, why and a snapshot of the totals.
 */
class VoidInvoice extends UseCase
{
    public function __construct(public readonly Invoice $invoice, public readonly string $reason, public readonly ?string $userId = null) {}

    public function execute(): Invoice
    {
        BillingLog::ensureTrace();
        $invoice = $this->invoice->fresh();
        self::assertVoidable($invoice, $this->reason);
        $userId = $this->userId ?? (auth()->check() ? (string) auth()->id() : null);

        DB::transaction(function () use ($invoice, $userId) {
            $released = Allocations::releaseInvoice($invoice);
            $invoice->update([
                'status' => InvoiceStatus::Void, 'balance' => 0, 'void_reason' => $this->reason,
                'voided_at' => now(), 'voided_by' => $userId,
            ]);
            $invoice->log('voided', ['reason' => $this->reason, 'total' => $invoice->total, 'released' => $released], $userId);
        });

        (new RunDunning(clientId: $invoice->client_id, markOverdue: false))->execute();
        Activity::record('invoice', $invoice->id, 'voided', $invoice->client_id, ['number' => $invoice->displayNumber(), 'reason' => $this->reason, 'total' => $invoice->total], $invoice->tenant_id, $userId);
        BillingLog::info('invoice voided', ['invoice_id' => $invoice->id, 'number' => $invoice->number, 'reason' => $this->reason]);

        return $invoice->fresh();
    }

    public static function assertVoidable(Invoice $invoice, string $reason): void
    {
        if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::Overdue, InvoiceStatus::Paid], true)) {
            throw new ConflictException('Solo se anulan facturas emitidas.');
        }
        if (trim($reason) === '') {
            throw new ConflictException('El motivo es obligatorio.');
        }
    }
}
