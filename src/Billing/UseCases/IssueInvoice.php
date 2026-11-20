<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Innertia\Billing\Contracts\PaymentLinkProvider;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\PaymentMethod;
use Innertia\Billing\Events\InvoiceIssued;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\Support\BillingNotices;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * draft → issued: assigns the next number, snapshots the billing data (space overrides on top of
 * the client's in per_space; the client's in consolidated), due_at = issued_at + payment_terms_days,
 * applies the client's credit (unallocated payments), creates the payment link when the method is
 * automatic and a PaymentLinkProvider is configured, then emails the billing contact (PDF attached)
 * and notices the space admins. Folio and an attached PDF (File id) are optional (SII comes later).
 */
class IssueInvoice extends UseCase
{
    public int $timeout = 120;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly ?string $folio = null,
        public readonly ?string $pdfFileId = null,
        public readonly bool $send = true,
    ) {}

    public function execute(): Invoice
    {
        BillingLog::ensureTrace();
        $invoice = $this->invoice->fresh();

        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new ConflictException('Solo se puede emitir una factura en borrador.');
        }
        if ($invoice->total <= 0) {
            throw new ConflictException('La factura no tiene monto.');
        }

        $client = $invoice->client;
        $now = now();

        $this->assignNumber($invoice, fn () => $invoice->update([
            'status'       => InvoiceStatus::Issued,
            'folio'        => $this->folio ?? $invoice->folio,
            'pdf_file_id'  => $this->pdfFileId ?? $invoice->pdf_file_id,
            'billing_data' => $invoice->tenant_id !== null ? ($invoice->tenant?->billingData() ?? $client->billingData()) : $client->billingData(),
            'issued_at'    => $now,
            'due_at'       => $now->copy()->addDays((int) ($client->payment_terms_days ?? 30)),
            'balance'      => $invoice->total,
            'trace_id'     => traceId() ?: $invoice->trace_id,
        ]));

        $invoice->log('issued', ['number' => $invoice->number, 'folio' => $invoice->folio, 'total' => $invoice->total]);

        $this->applyCredit($invoice);
        $invoice->refresh();

        if ($invoice->status === InvoiceStatus::Issued && $invoice->payment_method === PaymentMethod::Automatic) {
            $this->createLink($invoice);
        }

        BillingLog::info('invoice issued', [
            'invoice_id' => $invoice->id, 'number' => $invoice->number, 'client_id' => $invoice->client_id,
            'tenant_id' => $invoice->tenant_id, 'total' => $invoice->total, 'balance' => $invoice->balance,
        ]);
        event(new InvoiceIssued($invoice));

        if ($this->send) {
            (new SendInvoice($invoice))->execute();
            BillingNotices::invoiceIssued($invoice);
        }

        return $invoice->fresh();
    }

    /** next number = max + 1, retried on a unique collision (concurrent issues). */
    private function assignNumber(Invoice $invoice, \Closure $update): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                DB::transaction(function () use ($invoice, $update) {
                    $invoice->number = (int) Invoice::query()->max('number') + 1;
                    $update();
                });

                return;
            } catch (QueryException $e) {
                if ($attempt === 4) {
                    throw $e;
                }
            }
        }
    }

    /** Client credit (unallocated payments, oldest first) pays the new invoice. */
    private function applyCredit(Invoice $invoice): void
    {
        $credits = Payment::query()->where('client_id', $invoice->client_id)->where('unallocated_amount', '>', 0)->orderBy('received_at')->get();

        foreach ($credits as $payment) {
            if ($invoice->fresh()->balance <= 0) {
                break;
            }
            ApplyPayment::allocate($payment, $invoice->fresh(), 'credit');
        }
    }

    private function createLink(Invoice $invoice): void
    {
        try {
            $link = app(PaymentLinkProvider::class)->linkFor($invoice);
        } catch (\Throwable $e) {
            report($e);
            BillingLog::warning('payment link failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);

            return;
        }

        if ($link !== null) {
            $invoice->update(['payment_link' => $link['url'], 'charge_id' => $link['charge_id'] ?? null]);
            $invoice->log('payment_link_created', ['charge_id' => $link['charge_id'] ?? null]);
        }
    }
}
