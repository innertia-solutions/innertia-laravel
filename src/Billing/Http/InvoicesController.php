<?php

namespace Innertia\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Support\InvoicePdf;
use Innertia\Billing\UseCases\IssueInvoice;
use Innertia\Billing\UseCases\ReissueInvoice;
use Innertia\Billing\UseCases\SendInvoice;
use Innertia\Billing\UseCases\VoidInvoice;
use Innertia\Billing\UseCases\WaiveLateFees;
use Innertia\Exceptions\ConflictException;
use Symfony\Component\HttpFoundation\Response;

/** Platform: every invoice. */
class InvoicesController extends Controller
{
    /** GET billing/invoices?status=&client_id=&tenant_key=&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'     => ['nullable', Rule::enum(InvoiceStatus::class)],
            'client_id'  => 'nullable|uuid',
            'tenant_key' => 'nullable|string|max:80',
            'per_page'   => 'nullable|integer|min:1|max:100',
        ]);

        $tenantId = null;
        if (! empty($data['tenant_key'])) {
            $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;
            $tenantId = (string) ($model::query()->where('key', $data['tenant_key'])->value('id') ?? '-');
        }

        $page = Invoice::query()
            ->with(['client', 'tenant'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['client_id'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->when($tenantId, fn ($q) => $q->where(fn ($w) => $w->where('tenant_id', $tenantId)
                ->orWhereIn('id', \Innertia\Billing\Models\Statement::query()->where('tenant_id', $tenantId)->whereNotNull('invoice_id')->select('invoice_id'))))
            ->orderByRaw("CASE WHEN status = 'draft' THEN 0 ELSE 1 END")
            ->orderByDesc('period_end')
            ->orderByDesc('number')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn ($i) => Present::invoice($i))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(string $invoice): JsonResponse
    {
        return response()->json(Present::invoice(Invoice::with(['client', 'tenant'])->findOrFail($invoice), detail: true));
    }

    public function issue(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate([
            'folio'       => 'nullable|string|max:40',
            'pdf_file_id' => 'nullable|string|max:64',
            'send'        => 'sometimes|boolean',
        ]);

        $issued = (new IssueInvoice(Invoice::findOrFail($invoice), $data['folio'] ?? null, $data['pdf_file_id'] ?? null, $data['send'] ?? true))->execute();

        return response()->json(Present::invoice($issued, detail: true));
    }

    public function resend(string $invoice): JsonResponse
    {
        $model = Invoice::findOrFail($invoice);
        if (! in_array($model->status, [InvoiceStatus::Issued, InvoiceStatus::Overdue, InvoiceStatus::Paid], true)) {
            throw new ConflictException('Solo se reenvían facturas emitidas.');
        }

        return response()->json(['sent' => (new SendInvoice($model))->execute()]);
    }

    /** POST billing/invoices/{id}/void {reason} */
    public function void(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:250']);

        return response()->json(Present::invoice((new VoidInvoice(Invoice::findOrFail($invoice), $data['reason']))->execute(), detail: true));
    }

    /**
     * POST billing/invoices/{id}/reissue {reason, billing_data?, refresh_billing_data?, lines?: [{description, amount, tenant_key?}],
     * due_at?, folio?, send?} → the new invoice.
     */
    public function reissue(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate([
            'reason'               => 'required|string|max:250',
            'billing_data'         => 'nullable|array',
            'billing_data.*'       => 'nullable|string|max:250',
            'refresh_billing_data' => 'sometimes|boolean',
            'lines'                => 'nullable|array|max:20',
            'lines.*.description'  => 'required|string|max:200',
            'lines.*.amount'       => 'required|integer|not_in:0',
            'lines.*.tenant_key'   => 'nullable|string|max:80',
            'due_at'               => 'nullable|date_format:Y-m-d',
            'folio'                => 'nullable|string|max:40',
            'send'                 => 'sometimes|boolean',
        ]);

        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;
        $lines = array_map(fn ($l) => [
            'description' => $l['description'],
            'amount'      => (int) $l['amount'],
            'tenant_id'   => ! empty($l['tenant_key']) ? (string) ($model::query()->where('key', $l['tenant_key'])->value('id') ?? '-') : null,
        ], $data['lines'] ?? []);

        $new = (new ReissueInvoice(
            invoice: Invoice::findOrFail($invoice),
            reason: $data['reason'],
            billingData: $data['billing_data'] ?? null,
            lines: $lines,
            refreshBillingData: (bool) ($data['refresh_billing_data'] ?? false),
            dueAt: ! empty($data['due_at']) ? \Innertia\Billing\Support\BillingClock::date($data['due_at'])->endOfDay() : null,
            send: (bool) ($data['send'] ?? true),
            folio: $data['folio'] ?? null,
        ))->execute();

        return response()->json(Present::invoice($new, detail: true), 201);
    }

    /** POST billing/invoices/{id}/waive-late-fees {reason} */
    public function waiveLateFees(Request $request, string $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:250']);

        return response()->json((new WaiveLateFees(Invoice::findOrFail($invoice), $data['reason']))->execute());
    }

    public function pdf(string $invoice): Response
    {
        $pdf = new InvoicePdf(Invoice::findOrFail($invoice));

        return $pdf->download($pdf->filename());
    }
}
