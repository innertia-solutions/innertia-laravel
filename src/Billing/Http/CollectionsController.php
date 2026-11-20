<?php

namespace Innertia\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Innertia\Billing\Enums\AdjustmentType;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\Adjustment;
use Innertia\Billing\Models\ClientTerms;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Support\AccountStatement;
use Innertia\Billing\Support\XlsxExport;
use Innertia\Billing\UseCases\ApplyPayment;
use Innertia\Billing\UseCases\CancelAdjustment;
use Innertia\Billing\UseCases\CreateAdjustment;
use Innertia\Billing\UseCases\CreateCreditNote;
use Innertia\Billing\UseCases\SaveClientTerms;
use Innertia\Billing\Models\Payment;
use Innertia\Exceptions\ConflictException;
use Innertia\Exceptions\NotFoundException;
use Innertia\Saas\Models\Client;
use Symfony\Component\HttpFoundation\Response;

/** Platform: adjustments, credit notes, collection terms, client credit, account statement and activity log. */
class CollectionsController extends Controller
{
    /** GET billing/adjustments?client_id=&tenant_key=&credit_notes=&status= */
    public function adjustments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id'    => 'nullable|uuid',
            'tenant_key'   => 'nullable|string|max:80',
            'credit_notes' => 'nullable|boolean',
            'status'       => 'nullable|in:pending,applied,canceled',
        ]);
        $tenantId = ! empty($data['tenant_key']) ? $this->tenantId($data['tenant_key']) : null;

        $rows = Adjustment::query()
            ->when($data['client_id'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->when($tenantId, fn ($q) => $q->where(fn ($w) => $w->where('tenant_id', $tenantId)
                ->orWhere(fn ($c) => $c->whereNull('tenant_id')->where('client_id', $this->clientOf($tenantId)))))
            ->when(isset($data['credit_notes']), fn ($q) => $request->boolean('credit_notes') ? $q->whereNotNull('number') : $q->whereNull('number'))
            ->when(($data['status'] ?? null) === 'pending', fn ($q) => $q->whereNull('canceled_at')->whereNull('consumed_at'))
            ->when(($data['status'] ?? null) === 'applied', fn ($q) => $q->whereNull('canceled_at')->whereNotNull('consumed_at'))
            ->when(($data['status'] ?? null) === 'canceled', fn ($q) => $q->whereNotNull('canceled_at'))
            ->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($a) => Present::adjustment($a))->values()]);
    }

    /** POST billing/adjustments {tenant_key | client_id, type, amount | percent, recurring, starts_at, ends_at, reason} */
    public function storeAdjustment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_key' => 'nullable|required_without:client_id|string|max:80',
            'client_id'  => 'nullable|required_without:tenant_key|uuid|exists:clients,id',
            'type'       => ['required', Rule::enum(AdjustmentType::class)],
            'amount'     => 'nullable|required_without:percent|integer|min:1',
            'percent'    => 'nullable|required_without:amount|numeric|gt:0|max:100',
            'recurring'  => 'sometimes|boolean',
            'starts_at'  => 'nullable|date_format:Y-m-d',
            'ends_at'    => 'nullable|date_format:Y-m-d|after_or_equal:starts_at',
            'reason'     => 'required|string|max:250',
        ]);

        $adjustment = (new CreateAdjustment(
            type: AdjustmentType::from($data['type']),
            reason: $data['reason'],
            tenantId: ! empty($data['tenant_key']) ? $this->tenantId($data['tenant_key']) : null,
            clientId: $data['client_id'] ?? null,
            amount: isset($data['amount']) ? (int) $data['amount'] : null,
            percent: isset($data['percent']) ? (float) $data['percent'] : null,
            recurring: (bool) ($data['recurring'] ?? false),
            startsAt: $data['starts_at'] ?? null,
            endsAt: $data['ends_at'] ?? null,
        ))->execute();

        return response()->json(Present::adjustment($adjustment), 201);
    }

    public function cancelAdjustment(Request $request, string $adjustment): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:250']);

        return response()->json(Present::adjustment((new CancelAdjustment($adjustment, $data['reason']))->execute()));
    }

    /** POST billing/credit-notes {client_id, amount, reason, target: invoice|balance|next_period, invoice_id?, tenant_key?} */
    public function storeCreditNote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id'  => 'required|uuid|exists:clients,id',
            'amount'     => 'required|integer|min:1',
            'reason'     => 'required|string|max:250',
            'target'     => ['required', Rule::in(CreateCreditNote::TARGETS)],
            'invoice_id' => 'nullable|required_if:target,invoice|uuid',
            'tenant_key' => 'nullable|required_if:target,next_period|string|max:80',
        ]);

        $note = (new CreateCreditNote(
            clientId: $data['client_id'],
            amount: (int) $data['amount'],
            reason: $data['reason'],
            target: $data['target'],
            invoiceId: $data['invoice_id'] ?? null,
            tenantId: ! empty($data['tenant_key']) ? $this->tenantId($data['tenant_key']) : null,
        ))->execute();

        return response()->json(Present::adjustment($note), 201);
    }

    public function terms(string $client): JsonResponse
    {
        return response()->json(SaveClientTerms::present(Client::query()->findOrFail($client), ClientTerms::for($client)));
    }

    public function saveTerms(Request $request, string $client): JsonResponse
    {
        Client::query()->findOrFail($client);
        $data = $request->validate([
            'late_fee_percent'     => 'sometimes|numeric|min:0|max:50',
            'late_fee_basis'       => 'sometimes|in:monthly,daily',
            'late_fee_cap_percent' => 'sometimes|nullable|numeric|min:0|max:1000',
            'late_fee_exempt'      => 'sometimes|boolean',
            'reminders_enabled'    => 'sometimes|boolean',
        ]);

        return response()->json((new SaveClientTerms($client, $data))->execute());
    }

    /** POST billing/clients/{client}/apply-credit {invoice_id?}: applies the client's credit now. */
    public function applyCredit(Request $request, string $client): JsonResponse
    {
        $data = $request->validate(['invoice_id' => 'nullable|uuid']);
        $invoice = null;
        if (! empty($data['invoice_id'])) {
            $invoice = Invoice::query()->where('client_id', $client)->find($data['invoice_id']);
            if (! $invoice || ! $invoice->status->isOpen()) {
                throw new ConflictException('La factura no está pendiente de pago.');
            }
        }

        $applied = 0;
        foreach (Payment::query()->where('client_id', $client)->where('unallocated_amount', '>', 0)->orderBy('received_at')->get() as $payment) {
            $before = $payment->unallocated_amount;
            $after = (new ApplyPayment($payment, $invoice))->execute()->unallocated_amount;
            $applied += $before - $after;
        }
        if ($applied > 0) {
            Activity::record('credit', $client, 'applied', $client, ['amount' => $applied, 'invoice_id' => $invoice?->id]);
        }

        return response()->json([
            'applied' => $applied,
            'credit_available' => (int) Payment::query()->where('client_id', $client)->sum('unallocated_amount'),
        ]);
    }

    /** GET billing/clients/{client}/account?from=&to=&tenant_key= */
    public function account(Request $request, string $client): JsonResponse
    {
        [$from, $to, $tenantId] = $this->accountParams($request);

        return response()->json(AccountStatement::build($client, $from, $to, $tenantId));
    }

    public function exportAccount(Request $request, string $client): Response
    {
        [$from, $to, $tenantId] = $this->accountParams($request);
        $account = AccountStatement::build($client, $from, $to, $tenantId);

        return XlsxExport::download("estado-de-cuenta-{$account['client']['name']}",
            ['date' => 'Fecha', 'description' => 'Detalle', 'reference' => 'Referencia', 'charge' => 'Cargo', 'credit' => 'Abono', 'balance' => 'Saldo'],
            $account['movements'],
            [['Estado de cuenta', $account['client']['name'], $account['client']['tax_id']], ['Saldo inicial', $account['opening_balance']], ['Saldo final', $account['closing_balance']]],
        );
    }

    /** GET billing/activity?client_id=&subject_type= */
    public function activity(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => 'nullable|uuid', 'subject_type' => 'nullable|string|max:40', 'per_page' => 'nullable|integer|min:1|max:200']);

        $page = Activity::query()
            ->when($data['client_id'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->when($data['subject_type'] ?? null, fn ($q, $t) => $q->where('subject_type', $t))
            ->orderByDesc('created_at')
            ->paginate((int) ($data['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn ($a) => Present::activity($a))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    private function accountParams(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'tenant_key' => 'nullable|string|max:80',
        ]);

        return [$data['from'] ?? null, $data['to'] ?? null, ! empty($data['tenant_key']) ? $this->tenantId($data['tenant_key']) : null];
    }

    private function tenantId(string $key): string
    {
        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;
        $id = $model::query()->where('key', $key)->value('id');

        return $id !== null ? (string) $id : throw new NotFoundException("Espacio \"{$key}\" no encontrado.");
    }

    private function clientOf(string $tenantId): ?string
    {
        $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;

        return $model::query()->whereKey($tenantId)->value('client_id');
    }
}
