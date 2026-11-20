<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Support\Facades\Mail;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Support\BillingClock;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\Support\InvoicePdf;
use Innertia\Billing\Support\Money;
use Innertia\Mail\Branding;
use Innertia\Mail\NotificationMail;
use Innertia\Platform\Contracts\UseCase;

/** Emails an issued invoice (platform brand, PDF attached) to the billing email of its snapshot. */
class SendInvoice extends UseCase
{
    public int $timeout = 120;

    public function __construct(public readonly Invoice $invoice) {}

    public function execute(): bool
    {
        $invoice = $this->invoice->fresh();
        $email = $invoice->billing_data['billing_email'] ?? $invoice->client?->billing_email;

        if (blank($email)) {
            BillingLog::warning('invoice not sent: no billing email', ['invoice_id' => $invoice->id]);

            return false;
        }

        $tz = BillingClock::timezone();
        $number = $invoice->displayNumber();
        $mail = NotificationMail::make()
            ->type('billing.invoice')
            ->withBranding(Branding::platform())
            ->withSubject("Factura {$number}")
            ->title("Factura {$number}")
            ->line('Adjuntamos la factura del período ' . $invoice->period_start->format('d-m-Y') . ' al ' . $invoice->period_end->format('d-m-Y') . '.')
            ->table(['Detalle', 'Valor'], [
                ['Total', Money::format($invoice->total, $invoice->currency)],
                ['Saldo', Money::format($invoice->balance, $invoice->currency)],
                ['Vencimiento', $invoice->due_at?->timezone($tz)->format('d-m-Y') ?? '—'],
            ]);

        if ($invoice->payment_link && $invoice->balance > 0) {
            $mail->action('Pagar en línea', $invoice->payment_link);
        } elseif (filled($instructions = \Innertia\Billing\Support\BillingSettings::get('transfer_instructions'))) {
            $mail->panel((string) $instructions, 'info');
        }

        $mail->attachData((new InvoicePdf($invoice))->render(), "factura-{$number}.pdf", ['mime' => 'application/pdf']);

        Mail::to($email)->queue($mail);

        $invoice->update(['sent_at' => now()]);
        $invoice->log('sent', ['email' => $email]);
        BillingLog::info('invoice sent', ['invoice_id' => $invoice->id, 'email' => $email]);

        return true;
    }
}
