<?php

namespace Innertia\Billing\Support;

use Illuminate\Support\Collection;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Billing\Enums\SubscriptionStatus;
use Innertia\Billing\Meters;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Models\Plan;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Models\Subscription;
use Innertia\Billing\Models\UsageRecord;
use Innertia\Saas\Models\Client;

/**
 * Collection reports (platform). Every report returns {report, title, columns: {key: label}, rows, totals}
 * and exports to .xlsx with the same shape. Dates: local dates (billing time zone), both ends included.
 *
 *   revenue      invoiced (issued, not voided) by month | client | plan | product
 *   receivables  open invoices with balance and days past due
 *   aging        open debt per client by age (not due, 0–30, 31–60, 61–90, 90+)
 *   collections  money received (credit notes excluded) by month | method | client
 *   mrr          monthly recurring revenue of billable subscriptions by client | plan | product
 *   usage        metered consumption by space and meter
 */
final class BillingReports
{
    public const REPORTS = ['revenue', 'receivables', 'aging', 'collections', 'mrr', 'usage'];

    public static function run(string $report, array $params = []): array
    {
        $from = $params['from'] ?? BillingClock::today()->startOfYear()->format('Y-m-d');
        $to = $params['to'] ?? BillingClock::today()->format('Y-m-d');
        $group = $params['group_by'] ?? null;

        $result = match ($report) {
            'revenue'     => self::revenue($from, $to, $group ?? 'month'),
            'receivables' => self::receivables(),
            'aging'       => self::aging(),
            'collections' => self::collections($from, $to, $group ?? 'month'),
            'mrr'         => self::mrr($group ?? 'client'),
            'usage'       => self::usage($from, $to),
            default       => throw new \InvalidArgumentException("Unknown report {$report}"),
        };

        return ['report' => $report, 'from' => $from, 'to' => $to, 'group_by' => $group] + $result;
    }

    private static function revenue(string $from, string $to, string $group): array
    {
        [$start, $end] = BillingClock::bounds($from, $to);
        $invoices = Invoice::query()->with('client')
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value, InvoiceStatus::Paid->value])
            ->where('issued_at', '>=', $start)->where('issued_at', '<', $end)->get();

        if (in_array($group, ['plan', 'product'], true)) {
            $statements = Statement::query()->whereIn('invoice_id', $invoices->pluck('id'))->get();
            $plans = Plan::query()->whereIn('id', $statements->pluck('plan_id')->filter()->unique())->get()->keyBy('id');
            $rows = $statements->groupBy(fn ($s) => $group === 'plan' ? ($s->plan_id ?? '-') : ($plans->get($s->plan_id)?->product ?? '-'))
                ->map(fn (Collection $items, $key) => [
                    'key'      => (string) $key,
                    'label'    => $group === 'plan' ? ($plans->get($key)?->name ?? 'Sin plan') : ($key === '-' ? 'Sin producto' : $key),
                    'invoices' => $items->pluck('invoice_id')->unique()->count(),
                    'subtotal' => (int) $items->sum('subtotal'), 'tax' => (int) $items->sum('tax'), 'total' => (int) $items->sum('total'),
                ]);
        } else {
            $tz = BillingClock::timezone();
            $rows = $invoices->groupBy(fn (Invoice $i) => $group === 'client' ? (string) $i->client_id : $i->issued_at->copy()->timezone($tz)->format('Y-m'))
                ->map(fn (Collection $items, $key) => [
                    'key'      => (string) $key,
                    'label'    => $group === 'client' ? ($items->first()->client?->name ?? $key) : (string) $key,
                    'invoices' => $items->count(),
                    'subtotal' => (int) $items->sum('subtotal'), 'tax' => (int) $items->sum('tax'), 'total' => (int) $items->sum('total'),
                ]);
        }
        $rows = $rows->sortBy($group === 'month' ? 'key' : 'label')->values()->all();

        return [
            'title'   => 'Ingresos facturados',
            'columns' => ['label' => self::groupLabel($group), 'invoices' => 'Facturas', 'subtotal' => 'Neto', 'tax' => 'IVA', 'total' => 'Total'],
            'rows'    => $rows,
            'totals'  => self::sum($rows, ['invoices', 'subtotal', 'tax', 'total']),
        ];
    }

    private static function receivables(): array
    {
        $rows = Invoice::query()->with(['client', 'tenant'])
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value])->where('balance', '>', 0)
            ->orderBy('due_at')->get()
            ->map(fn (Invoice $i) => [
                'invoice_id' => $i->id, 'number' => $i->displayNumber(), 'client_id' => (string) $i->client_id,
                'client' => $i->client?->name, 'space' => $i->tenant_id ? $i->tenant?->name : 'Consolidada',
                'issued_at' => $i->issued_at?->timezone(BillingClock::timezone())->format('Y-m-d'),
                'due_at' => $i->dueDate()?->format('Y-m-d'), 'days_overdue' => AccountStatement::daysOverdue($i),
                'status' => $i->status->label(), 'total' => $i->total, 'balance' => $i->balance,
            ])->values()->all();

        return [
            'title'   => 'Cuentas por cobrar',
            'columns' => ['number' => 'Factura', 'client' => 'Cliente', 'space' => 'Espacio', 'issued_at' => 'Emisión', 'due_at' => 'Vencimiento',
                'days_overdue' => 'Días vencida', 'status' => 'Estado', 'total' => 'Total', 'balance' => 'Saldo'],
            'rows'    => $rows,
            'totals'  => self::sum($rows, ['total', 'balance']),
        ];
    }

    private static function aging(): array
    {
        $open = Invoice::query()->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value])->where('balance', '>', 0)->get();
        $clients = Client::query()->whereIn('id', $open->pluck('client_id')->unique())->get()->keyBy('id');

        $rows = $open->groupBy('client_id')->map(fn (Collection $items, $clientId) => [
            'client_id' => (string) $clientId, 'client' => $clients->get($clientId)?->name ?? $clientId,
        ] + AccountStatement::aging($items))->sortByDesc('total')->values()->all();

        return [
            'title'   => 'Antigüedad de deuda',
            'columns' => ['client' => 'Cliente'] + AccountStatement::BUCKETS + ['total' => 'Total'],
            'rows'    => $rows,
            'totals'  => self::sum($rows, [...array_keys(AccountStatement::BUCKETS), 'total']),
        ];
    }

    private static function collections(string $from, string $to, string $group): array
    {
        [$start, $end] = BillingClock::bounds($from, $to);
        $payments = Payment::query()->where('method', '!=', PaymentChannel::Credit->value)
            ->where('received_at', '>=', $start)->where('received_at', '<', $end)->get();
        $clients = Client::query()->whereIn('id', $payments->pluck('client_id')->unique())->get()->keyBy('id');
        $tz = BillingClock::timezone();
        $methods = ['transfer' => 'Transferencia', 'link' => 'Pago en línea', 'manual' => 'Manual'];

        $rows = $payments->groupBy(fn (Payment $p) => match ($group) {
            'method' => $p->method->value,
            'client' => (string) $p->client_id,
            default  => $p->received_at->copy()->timezone($tz)->format('Y-m'),
        })->map(fn (Collection $items, $key) => [
            'key'      => (string) $key,
            'label'    => match ($group) { 'method' => $methods[$key] ?? $key, 'client' => $clients->get($key)?->name ?? $key, default => (string) $key },
            'payments' => $items->count(),
            'amount'   => (int) $items->sum('amount'),
            'credit'   => (int) $items->sum('unallocated_amount'),
        ])->sortBy($group === 'month' ? 'key' : 'label')->values()->all();

        return [
            'title'   => 'Recaudación',
            'columns' => ['label' => self::groupLabel($group), 'payments' => 'Pagos', 'amount' => 'Recaudado', 'credit' => 'Sin aplicar (saldo a favor)'],
            'rows'    => $rows,
            'totals'  => self::sum($rows, ['payments', 'amount', 'credit']),
        ];
    }

    private static function mrr(string $group): array
    {
        $subscriptions = Subscription::query()->with(['plan', 'tenant.client'])
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Suspended->value])->get();

        $rows = $subscriptions->groupBy(fn (Subscription $s) => match ($group) {
            'plan'    => (string) $s->plan_id,
            'product' => $s->plan?->product ?? '-',
            default   => (string) ($s->tenant?->client_id ?? '-'),
        })->map(fn (Collection $items, $key) => [
            'key'    => (string) $key,
            'label'  => match ($group) {
                'plan'    => $items->first()->plan?->name ?? $key,
                'product' => $key === '-' ? 'Sin producto' : $key,
                default   => $items->first()->tenant?->client?->name ?? $key,
            },
            'spaces' => $items->count(),
            'mrr'    => (int) $items->sum(fn (Subscription $s) => $s->plan?->monthlyBase($s->overrides) ?? 0),
        ])->sortByDesc('mrr')->values()->all();

        return [
            'title'   => 'Ingreso recurrente mensual (MRR)',
            'columns' => ['label' => self::groupLabel($group), 'spaces' => 'Espacios', 'mrr' => 'MRR'],
            'rows'    => $rows,
            'totals'  => self::sum($rows, ['spaces', 'mrr']),
        ];
    }

    private static function usage(string $from, string $to): array
    {
        [$start, $end] = BillingClock::bounds($from, $to);
        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;
        $records = UsageRecord::query()->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->selectRaw('tenant_id, meter, SUM(quantity) as quantity, SUM(cost) as cost, COUNT(*) as records')
            ->groupBy('tenant_id', 'meter')->get();
        $tenants = $model::query()->whereIn('id', $records->pluck('tenant_id')->unique())->get()->keyBy(fn ($t) => (string) $t->id);

        $rows = $records->map(fn ($r) => [
            'space' => $tenants->get((string) $r->tenant_id)?->name ?? $r->tenant_id,
            'meter' => Meters::get($r->meter)?->label ?? $r->meter,
            'unit' => Meters::get($r->meter)?->unit,
            'quantity' => round((float) $r->quantity, 4), 'cost' => (int) $r->cost, 'records' => (int) $r->records,
        ])->sortBy(['space', 'meter'])->values()->all();

        return [
            'title'   => 'Consumo por espacio',
            'columns' => ['space' => 'Espacio', 'meter' => 'Medidor', 'unit' => 'Unidad', 'quantity' => 'Cantidad', 'cost' => 'Costo', 'records' => 'Registros'],
            'rows'    => $rows,
            'totals'  => self::sum($rows, ['cost', 'records']),
        ];
    }

    private static function groupLabel(?string $group): string
    {
        return ['month' => 'Mes', 'client' => 'Cliente', 'plan' => 'Plan', 'product' => 'Producto', 'method' => 'Medio de pago'][$group] ?? 'Grupo';
    }

    private static function sum(array $rows, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (int) array_sum(array_column($rows, $key));
        }

        return $out;
    }
}
