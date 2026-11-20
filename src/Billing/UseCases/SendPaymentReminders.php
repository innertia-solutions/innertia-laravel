<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\ClientTerms;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Reminder;
use Innertia\Billing\Support\BillingClock;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\Support\BillingNotices;
use Innertia\Billing\Support\BillingSettings;
use Innertia\Billing\Support\Money;
use Innertia\Mail\Branding;
use Innertia\Mail\NotificationMail;
use Innertia\Platform\Contracts\UseCase;

/**
 * Daily payment reminders (innertia:billing:reminders, queued) for open invoices with balance, by email
 * to the billing contact (platform brand) and a notice to the space admins:
 *
 *   before   N days before the due date (settings reminder_days_before, e.g. "7,3")
 *   due      the day it is due (reminder_on_due)
 *   overdue  the day after and then every reminder_overdue_every_days, up to reminder_overdue_max;
 *            from reminder_escalate_after on, escalated: copy to reminder_escalation_email + critical notice.
 *
 * Idempotent: unique(invoice, kind, sequence) — running it twice a day never resends. Clients with
 * reminders off (billing_client_terms) are skipped. Missed days are not caught up (no reminder storms).
 *
 * @return array{sent: int, escalated: int, skipped: int}
 */
class SendPaymentReminders extends UseCase
{
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly ?string $today = null) {}

    public function execute(): array
    {
        BillingLog::ensureTrace();
        $counts = ['sent' => 0, 'escalated' => 0, 'skipped' => 0];
        if (! BillingSettings::bool(BillingSettings::get('reminders_enabled'))) {
            return $counts;
        }

        $today = $this->today ? BillingClock::date($this->today) : BillingClock::today();
        $daysBefore = BillingSettings::daysBefore();
        $onDue = BillingSettings::bool(BillingSettings::get('reminder_on_due'));
        $every = max(1, (int) BillingSettings::get('reminder_overdue_every_days'));
        $max = max(0, (int) BillingSettings::get('reminder_overdue_max'));
        $escalateAfter = max(0, (int) BillingSettings::get('reminder_escalate_after'));
        $escalationEmail = BillingSettings::get('reminder_escalation_email');

        $invoices = Invoice::query()->with('client')
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value])
            ->where('balance', '>', 0)->whereNotNull('due_at')
            ->orderBy('due_at')->get();
        $muted = ClientTerms::query()->where('reminders_enabled', false)->pluck('client_id')->map(fn ($id) => (string) $id)->all();

        foreach ($invoices as $invoice) {
            if (in_array((string) $invoice->client_id, $muted, true)) {
                $counts['skipped']++;
                continue;
            }

            $daysToDue = (int) $today->diffInDays($invoice->dueDate(), false);
            [$kind, $sequence] = match (true) {
                $daysToDue > 0 && in_array($daysToDue, $daysBefore, true) => ['before', $daysToDue],
                $daysToDue === 0 && $onDue                                => ['due', 0],
                $daysToDue < 0 && (-$daysToDue - 1) % $every === 0        => ['overdue', intdiv(-$daysToDue - 1, $every) + 1],
                default                                                   => [null, 0],
            };
            if ($kind === null || ($kind === 'overdue' && $sequence > $max)) {
                continue;
            }

            try {
                $sent = $this->send($invoice, $kind, $sequence, -$daysToDue, $today->format('Y-m-d'),
                    $kind === 'overdue' && $sequence > $escalateAfter ? $escalationEmail : false);
                if ($sent === null) {
                    continue;
                }
                $counts['sent']++;
                $counts['escalated'] += $sent ? 1 : 0;
            } catch (\Throwable $e) {
                report($e);
                BillingLog::error('payment reminder failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            }
        }

        BillingLog::info('payment reminders finished', $counts);

        return $counts;
    }

    /** @return bool|null escalated, or null when already sent / no email */
    private function send(Invoice $invoice, string $kind, int $sequence, int $daysOverdue, string $today, string|false|null $escalateTo): ?bool
    {
        $email = $invoice->billing_data['billing_email'] ?? $invoice->client?->billing_email;
        if (blank($email)) {
            return null;
        }
        $escalated = $escalateTo !== false;

        try {
            Reminder::create([
                'invoice_id' => $invoice->id, 'kind' => $kind, 'sequence' => $sequence, 'sent_on' => $today,
                'email' => $email, 'escalated' => $escalated, 'trace_id' => traceId() ?: null, 'created_at' => now(),
            ]);
        } catch (QueryException) {
            return null; // already sent
        }

        $number = $invoice->displayNumber();
        $due = $invoice->dueDate()->format('d-m-Y');
        [$subject, $line] = match ($kind) {
            'before' => ["Recordatorio: la factura {$number} vence el {$due}", "Te recordamos que la factura {$number} vence el {$due}."],
            'due'    => ["La factura {$number} vence hoy", "La factura {$number} vence hoy ({$due})."],
            default  => [
                ($escalated ? 'Aviso de cobranza: ' : '') . "Factura {$number} vencida hace {$daysOverdue} " . ($daysOverdue === 1 ? 'día' : 'días'),
                "La factura {$number} venció el {$due} y registra saldo pendiente." . ($escalated ? ' Regulariza el pago para evitar la suspensión del servicio.' : ''),
            ],
        };

        $mail = NotificationMail::make()
            ->type('billing.reminder')
            ->withBranding(Branding::platform())
            ->withSubject($subject)
            ->title($subject)
            ->line($line)
            ->table(['Detalle', 'Valor'], [
                ['Total', Money::format($invoice->total, $invoice->currency)],
                ['Saldo pendiente', Money::format($invoice->balance, $invoice->currency)],
                ['Vencimiento', $due],
            ]);
        if ($invoice->payment_link) {
            $mail->action('Pagar en línea', $invoice->payment_link);
        } elseif (filled($instructions = BillingSettings::get('transfer_instructions'))) {
            $mail->panel((string) $instructions, 'info');
        }

        $pending = Mail::to($email);
        if ($escalated && filled($escalateTo)) {
            $pending->cc($escalateTo);
        }
        $pending->queue($mail);

        BillingNotices::reminder($invoice, $subject, 'Saldo ' . Money::format($invoice->balance, $invoice->currency) . " · vence el {$due}", $escalated);
        $invoice->log('reminder_sent', ['kind' => $kind, 'sequence' => $sequence, 'email' => $email, 'escalated' => $escalated], 'system');
        BillingLog::info('payment reminder sent', ['invoice_id' => $invoice->id, 'kind' => $kind, 'sequence' => $sequence, 'escalated' => $escalated]);

        return $escalated;
    }
}
