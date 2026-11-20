<?php

namespace Innertia\Billing\Support;

use Innertia\Billing\Billing;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Subscription;
use Innertia\Notifications\Notice;
use Innertia\Notifications\NoticeType;
use Innertia\Notifications\Notifier;
use Innertia\Notifications\Recipients;

/**
 * Bell + email notices of billing to the space admins (users with `billing.view` in the space).
 * Failures are logged, never thrown.
 */
final class BillingNotices
{
    public const INVOICE_ISSUED = 'billing.invoice_issued';
    public const PAYMENT_RECEIVED = 'billing.payment_received';
    public const PAST_DUE = 'billing.past_due';
    public const SUSPENDED = 'billing.suspended';
    public const REACTIVATED = 'billing.reactivated';

    /** @return NoticeType[] */
    public static function types(): array
    {
        $category = 'Facturación';

        return [
            new NoticeType(self::INVOICE_ISSUED, 'Factura emitida', 'Cuando se emite una factura del espacio.', category: $category),
            new NoticeType(self::PAYMENT_RECEIVED, 'Pago recibido', 'Cuando se registra un pago de una factura.', category: $category),
            new NoticeType(self::PAST_DUE, 'Factura vencida', 'Cuando una factura del espacio vence sin pago.', critical: true, debounceMinutes: 1440, category: $category),
            new NoticeType(self::SUSPENDED, 'Espacio suspendido', 'Cuando el espacio queda en solo lectura por deuda.', critical: true, category: $category),
            new NoticeType(self::REACTIVATED, 'Espacio reactivado', 'Cuando el espacio vuelve a estar al día.', category: $category),
        ];
    }

    public static function invoiceIssued(Invoice $invoice): void
    {
        foreach (self::tenantIds($invoice) as $tenantId) {
            self::send($tenantId, self::INVOICE_ISSUED, 'Nueva factura ' . $invoice->displayNumber(),
                'Total ' . Money::format($invoice->total, $invoice->currency) . ' · vence el ' . $invoice->due_at?->timezone(BillingClock::timezone())->format('d-m-Y'),
                "invoice:{$invoice->id}");
        }
    }

    public static function paymentReceived(Invoice $invoice, int $amount): void
    {
        foreach (self::tenantIds($invoice) as $tenantId) {
            self::send($tenantId, self::PAYMENT_RECEIVED, 'Pago recibido · factura ' . $invoice->displayNumber(),
                'Monto ' . Money::format($amount, $invoice->currency) . ' · saldo ' . Money::format($invoice->balance, $invoice->currency),
                "invoice:{$invoice->id}:payment");
        }
    }

    public static function subscription(Subscription $subscription, string $type): void
    {
        [$title, $body] = match ($type) {
            self::PAST_DUE    => ['Tienes facturas vencidas', 'Paga para evitar la suspensión del espacio.'],
            self::SUSPENDED   => ['Espacio en solo lectura', 'El espacio quedó suspendido por facturas vencidas. Puedes pagar desde Facturación.'],
            self::REACTIVATED => ['Espacio al día', 'El espacio volvió a operar normalmente.'],
            default           => [$type, ''],
        };

        self::send((string) $subscription->tenant_id, $type, $title, $body, "subscription:{$subscription->id}");

        if ($type === self::REACTIVATED) {
            try {
                Billing::inTenant((string) $subscription->tenant_id, function () use ($subscription) {
                    Notifier::resolve(self::PAST_DUE, "subscription:{$subscription->id}");
                    Notifier::resolve(self::SUSPENDED, "subscription:{$subscription->id}");
                });
            } catch (\Throwable $e) {
                BillingLog::warning('billing notice resolve failed', ['subscription_id' => $subscription->id, 'error' => $e->getMessage()]);
            }
        }
    }

    /** @return string[] spaces involved in an invoice (consolidated: every space with a statement in it) */
    private static function tenantIds(Invoice $invoice): array
    {
        return $invoice->tenant_id !== null
            ? [(string) $invoice->tenant_id]
            : $invoice->statements()->pluck('tenant_id')->map(fn ($id) => (string) $id)->unique()->values()->all();
    }

    private static function send(string $tenantId, string $type, string $title, string $body, string $subjectKey): void
    {
        try {
            Billing::inTenant($tenantId, function () use ($type, $title, $body, $subjectKey, $tenantId) {
                Notifier::send(new Notice(
                    type: $type,
                    title: $title,
                    body: $body,
                    url: (string) config('innertia.billing.backoffice_path', '/backoffice/billing'),
                    recipients: Recipients::withPermission(Billing::PERMISSION),
                    subjectKey: $subjectKey,
                    tenantId: $tenantId,
                ));
            });
        } catch (\Throwable $e) {
            BillingLog::warning('billing notice failed', ['type' => $type, 'tenant_id' => $tenantId, 'error' => $e->getMessage()]);
        }
    }
}
