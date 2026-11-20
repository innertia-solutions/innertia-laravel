<?php

namespace Innertia\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Innertia\Billing\Enums\PaymentChannel;
use Innertia\Billing\Models\Payment;
use Innertia\Billing\UseCases\RegisterPayment;

/** Platform: payments received (manual registration of transfers). */
class PaymentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => 'nullable|uuid', 'per_page' => 'nullable|integer|min:1|max:100']);

        $page = Payment::query()
            ->when($data['client_id'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->orderByDesc('received_at')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn ($p) => Present::payment($p))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id'   => 'required|uuid|exists:clients,id',
            'amount'      => 'required|integer|min:1',
            'method'      => ['sometimes', Rule::in([PaymentChannel::Transfer->value, PaymentChannel::Manual->value])],
            'reference'   => 'nullable|string|max:120',
            'received_at' => 'nullable|date',
            'invoice_id'  => 'nullable|uuid',
        ]);

        $payment = (new RegisterPayment(
            clientId: $data['client_id'],
            amount: (int) $data['amount'],
            method: PaymentChannel::from($data['method'] ?? 'transfer'),
            reference: $data['reference'] ?? null,
            receivedAt: $data['received_at'] ?? null,
            invoiceId: $data['invoice_id'] ?? null,
        ))->execute();

        return response()->json(Present::payment($payment), 201);
    }
}
