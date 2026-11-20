<?php

namespace Innertia\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\Models\Statement;
use Innertia\Billing\Support\InvoicePdf;
use Innertia\Billing\Support\SpaceOverview;
use Innertia\Exceptions\ForbiddenException;
use Innertia\Facades\Innertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backoffice (active space), read-only: plan, consumption of the period, invoices (with the space's
 * part in consolidated ones), payments and status. Testing spaces get {testing: true} and nothing else.
 */
class BackofficeController extends Controller
{
    public function overview(): JsonResponse
    {
        $tenant = $this->tenant();
        $overview = SpaceOverview::build($tenant);

        if ($overview['testing']) {
            return response()->json(['testing' => true, 'space' => $overview['space'], 'subscription' => null]);
        }

        $open = $this->invoiceQuery($tenant)->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value])->get();
        $overview['balance'] = [
            'open'    => (int) $open->sum('balance'),
            'overdue' => (int) $open->where('status', InvoiceStatus::Overdue)->sum('balance'),
            'next_due_at' => $open->min('due_at')?->toIso8601String(),
        ];

        return response()->json($overview);
    }

    public function invoices(): JsonResponse
    {
        $tenant = $this->tenant();
        $tenantId = (string) $tenant->getKey();
        $parts = Statement::query()->where('tenant_id', $tenantId)->whereNotNull('invoice_id')
            ->selectRaw('invoice_id, SUM(total) as total')->groupBy('invoice_id')->pluck('total', 'invoice_id');

        $invoices = $this->invoiceQuery($tenant)->where('status', '!=', InvoiceStatus::Draft->value)
            ->orderByDesc('issued_at')->limit(60)->get();

        return response()->json(['data' => $invoices->map(fn (Invoice $i) => Present::invoice($i) + [
            'space_part' => $i->isConsolidated() ? (int) ($parts[$i->id] ?? 0) : $i->total,
        ])->values()]);
    }

    public function invoice(string $invoice): JsonResponse
    {
        $model = $this->ownInvoice($invoice);
        $data = Present::invoice($model, detail: true);
        if ($model->isConsolidated()) {
            // Only this space's statements; other spaces of the client are not exposed.
            $tenantId = (string) $this->tenant()->getKey();
            $data['statements'] = array_values(array_filter($data['statements'], fn ($s) => $s['tenant_id'] === $tenantId));
        }
        unset($data['events']);

        return response()->json($data);
    }

    public function pdf(string $invoice): Response
    {
        $pdf = new InvoicePdf($this->ownInvoice($invoice));

        return $pdf->download($pdf->filename());
    }

    public function payments(): JsonResponse
    {
        $ids = $this->invoiceQuery($this->tenant())->pluck('id');

        $payments = Payment::query()
            ->whereHas('allocations', fn ($q) => $q->whereIn('invoice_id', $ids))
            ->with(['allocations' => fn ($q) => $q->whereIn('invoice_id', $ids)])
            ->orderByDesc('received_at')->limit(60)->get();

        return response()->json(['data' => $payments->map(fn (Payment $p) => [
            'id'          => $p->id,
            'method'      => $p->method->value,
            'reference'   => $p->reference,
            'received_at' => $p->received_at?->toIso8601String(),
            'amount'      => (int) $p->allocations->sum('amount'),
            'allocations' => $p->allocations->map(fn ($a) => ['invoice_id' => $a->invoice_id, 'amount' => $a->amount])->values(),
        ])->values()]);
    }

    private function tenant(): object
    {
        return Innertia::tenant() ?? throw new ForbiddenException('No hay un espacio activo.');
    }

    private function invoiceQuery(object $tenant)
    {
        $tenantId = (string) $tenant->getKey();

        return Invoice::query()->where(fn ($q) => $q->where('tenant_id', $tenantId)
            ->orWhereIn('id', Statement::query()->where('tenant_id', $tenantId)->whereNotNull('invoice_id')->select('invoice_id')));
    }

    private function ownInvoice(string $id): Invoice
    {
        $invoice = $this->invoiceQuery($this->tenant())->where('status', '!=', InvoiceStatus::Draft->value)->find($id);
        abort_if($invoice === null, 404);

        return $invoice;
    }
}
