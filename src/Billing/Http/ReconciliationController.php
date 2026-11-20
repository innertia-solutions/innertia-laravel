<?php

namespace Innertia\Billing\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\BankImport;
use Innertia\Billing\Models\BankTransaction;
use Innertia\Billing\Models\Invoice;
use Innertia\Billing\Support\BankStatementReader;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\UseCases\IgnoreTransaction;
use Innertia\Billing\UseCases\ImportBankStatement;
use Innertia\Billing\UseCases\MatchBankTransactions;
use Innertia\Billing\UseCases\ReconcileTransaction;
use Innertia\Billing\UseCases\UnmatchTransaction;
use Innertia\Exceptions\ConflictException;

/**
 * Platform: bank statement reconciliation. Upload a cartola (CSV/XLSX) with its column mapping
 * (preview first), the import runs on the queue, conclusive movements are applied alone and the rest
 * are matched by hand (client + invoices; partial payments and leftovers as client credit).
 */
class ReconciliationController extends Controller
{
    private const FILE_RULE = 'required|file|max:10240|extensions:csv,txt,xlsx';

    /** POST billing/reconciliation/preview (multipart: file, header_row?) → headers, sample rows, suggested mapping */
    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => self::FILE_RULE, 'header_row' => 'nullable|integer|min:1|max:200']);
        $file = $request->file('file');
        $rows = BankStatementReader::rows((string) file_get_contents($file->getRealPath()), BankStatementReader::format($file->getClientOriginalName()));
        if ($rows === []) {
            throw new ConflictException('El archivo está vacío.');
        }
        $header = BankStatementReader::header($rows, [], $request->integer('header_row') ?: null);

        return response()->json([
            'header_row' => $header['index'] + 1,
            'headers'    => array_values(array_filter($header['headers'], fn ($h) => $h !== '')),
            'rows'       => array_map(fn ($r) => array_map([BankStatementReader::class, 'cellString'], $r), array_slice($rows, $header['index'] + 1, 10)),
            'mapping'    => BankStatementReader::guessMapping($header['headers']),
            'last_mapping' => BankImport::query()->latest('created_at')->value('mapping'),
        ]);
    }

    /** POST billing/reconciliation/imports (multipart: file, mapping{…} or JSON string, bank?, header_row?) */
    public function store(Request $request): JsonResponse
    {
        if (is_string($request->input('mapping'))) {
            $request->merge(['mapping' => json_decode($request->input('mapping'), true) ?: []]);
        }
        $data = $request->validate([
            'file'                => self::FILE_RULE,
            'mapping'             => 'required|array',
            'mapping.date'        => 'required|string|max:120',
            'mapping.amount'      => 'nullable|required_without:mapping.credit|string|max:120',
            'mapping.credit'      => 'nullable|required_without:mapping.amount|string|max:120',
            'mapping.debit'       => 'nullable|string|max:120',
            'mapping.description' => 'nullable|string|max:120',
            'mapping.reference'   => 'nullable|string|max:120',
            'mapping.tax_id'      => 'nullable|string|max:120',
            'mapping.name'        => 'nullable|string|max:120',
            'bank'                => 'nullable|string|max:80',
            'header_row'          => 'nullable|integer|min:1|max:200',
        ]);

        $file = $request->file('file');
        $content = (string) file_get_contents($file->getRealPath());
        $hash = hash('sha256', $content);

        // Same file again → the same import (never duplicates). A failed one can be retried.
        $existing = BankImport::query()->where('file_hash', $hash)->first();
        if ($existing && $existing->status !== 'failed') {
            return response()->json(Present::bankImport($existing) + ['duplicate' => true]);
        }

        $format = BankStatementReader::format($file->getClientOriginalName());
        $attributes = [
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'file_hash' => $hash,
            'format'    => $format,
            'bank'      => $data['bank'] ?? null,
            'mapping'   => array_filter($data['mapping'], fn ($v) => filled($v)),
            'options'   => array_filter(['header_row' => $data['header_row'] ?? null]),
            'content'   => base64_encode($content),
            'status'    => 'pending',
            'created_by'=> auth()->check() ? (string) auth()->id() : null,
            'trace_id'  => BillingLog::ensureTrace(),
        ];
        $import = $existing ? tap($existing)->update($attributes) : BankImport::create($attributes);

        (new ImportBankStatement($import->id))->onQueue((string) config('innertia.billing.queue', 'use-cases'));

        return response()->json(Present::bankImport($import->fresh()) + ['duplicate' => false], 201);
    }

    public function imports(): JsonResponse
    {
        return response()->json(['data' => BankImport::query()->latest('created_at')->limit(50)->get()->map(fn ($i) => Present::bankImport($i))->values()]);
    }

    public function showImport(string $import): JsonResponse
    {
        return response()->json(Present::bankImport(BankImport::query()->findOrFail($import)));
    }

    /** GET billing/reconciliation/transactions?status=&import_id=&page= */
    public function transactions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'    => 'nullable|in:open,unmatched,suggested,matched,ignored',
            'import_id' => 'nullable|uuid',
            'client_id' => 'nullable|uuid',
            'per_page'  => 'nullable|integer|min:1|max:200',
        ]);

        $page = BankTransaction::query()->with('client')
            ->when(($data['status'] ?? null) === 'open', fn ($q) => $q->whereIn('status', [BankTransaction::UNMATCHED, BankTransaction::SUGGESTED]))
            ->when(($data['status'] ?? 'open') !== 'open' && ! empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when($data['import_id'] ?? null, fn ($q, $id) => $q->where('import_id', $id))
            ->when($data['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->orderByDesc('posted_at')->orderByDesc('amount')
            ->paginate((int) ($data['per_page'] ?? 50));

        $open = BankTransaction::query()->whereIn('status', [BankTransaction::UNMATCHED, BankTransaction::SUGGESTED]);

        return response()->json([
            'data'    => collect($page->items())->map(fn ($t) => Present::bankTransaction($t))->values(),
            'meta'    => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'summary' => ['open' => (clone $open)->count(), 'open_amount' => (int) (clone $open)->sum('amount')],
        ]);
    }

    /** GET billing/reconciliation/open-invoices?client_id=&q= : candidates for a manual match */
    public function openInvoices(Request $request): JsonResponse
    {
        $data = $request->validate(['client_id' => 'nullable|uuid', 'q' => 'nullable|string|max:80']);

        $invoices = Invoice::query()->with(['client', 'tenant'])
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value])->where('balance', '>', 0)
            ->when($data['client_id'] ?? null, fn ($q, $c) => $q->where('client_id', $c))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->whereHas('client', fn ($c) => $c
                ->where('name', 'like', '%' . addcslashes($term, '%_\\') . '%')
                ->orWhere('tax_id', 'like', '%' . addcslashes($term, '%_\\') . '%')))
            ->orderBy('due_at')->limit(100)->get();

        return response()->json(['data' => $invoices->map(fn ($i) => Present::invoice($i))->values()]);
    }

    /** POST billing/reconciliation/transactions/{id}/match {client_id, invoice_ids[]} */
    public function match(Request $request, string $transaction): JsonResponse
    {
        $data = $request->validate([
            'client_id'     => 'required|uuid|exists:clients,id',
            'invoice_ids'   => 'nullable|array|max:50',
            'invoice_ids.*' => 'uuid',
        ]);

        return response()->json(Present::bankTransaction((new ReconcileTransaction($transaction, $data['client_id'], $data['invoice_ids'] ?? []))->execute()));
    }

    public function unmatch(Request $request, string $transaction): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:250']);

        return response()->json(Present::bankTransaction((new UnmatchTransaction($transaction, $data['reason']))->execute()));
    }

    public function ignore(Request $request, string $transaction): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:250']);

        return response()->json(Present::bankTransaction((new IgnoreTransaction($transaction, $data['reason'] ?? null))->execute()));
    }

    public function restore(string $transaction): JsonResponse
    {
        return response()->json(Present::bankTransaction((new IgnoreTransaction($transaction, restore: true))->execute()));
    }

    /** POST billing/reconciliation/rematch {import_id?}: scores the open movements again (new invoices issued since). */
    public function rematch(Request $request): JsonResponse
    {
        $data = $request->validate(['import_id' => 'nullable|uuid']);

        return response()->json((new MatchBankTransactions($data['import_id'] ?? null))->execute());
    }
}
