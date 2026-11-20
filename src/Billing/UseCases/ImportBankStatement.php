<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\BankImport;
use Innertia\Billing\Models\BankTransaction;
use Innertia\Billing\Support\BankStatementReader;
use Innertia\Billing\Support\BankValues;
use Innertia\Billing\Support\BillingLog;
use Innertia\Platform\Contracts\UseCase;

/**
 * Processes an uploaded bank statement (queued: innertia.billing.queue). Reads the rows with the column
 * mapping, keeps the incoming movements (amount > 0 = credits; debits and rows without date are
 * skipped) and stores each one once: the row hash (date, amount, description, reference, RUT and the
 * occurrence of that same movement in the file) is unique, so re-importing an overlapping statement
 * never duplicates. Then runs the matching (auto-applies the conclusive ones).
 */
class ImportBankStatement extends UseCase
{
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly string $importId) {}

    public function execute(): BankImport
    {
        $import = BankImport::query()->findOrFail($this->importId);
        if ($import->trace_id) {
            app()->instance('trace_id', $import->trace_id);
        }
        BillingLog::ensureTrace();

        if (! in_array($import->status, ['pending', 'failed'], true)) {
            return $import;
        }
        $import->update(['status' => 'processing', 'error' => null]);

        try {
            $rows = BankStatementReader::rows((string) base64_decode((string) $import->content), $import->format);
            $items = BankStatementReader::mapped($rows, $import->mapping ?? [], $import->options['header_row'] ?? null);

            $counts = ['total' => 0, 'imported' => 0, 'duplicated' => 0, 'skipped' => 0];
            $seen = [];
            foreach ($items as $item) {
                $counts['total']++;
                $date = BankValues::date($item['date'] ?? null);
                $amount = isset($item['amount'])
                    ? BankValues::amount($item['amount'])
                    : (BankValues::amount($item['credit'] ?? null) ?? 0) - abs(BankValues::amount($item['debit'] ?? null) ?? 0);
                if ($date === null || $amount === null || $amount <= 0) {
                    $counts['skipped']++;
                    continue;
                }

                $description = trim((string) ($item['description'] ?? '')) ?: null;
                $reference = trim((string) ($item['reference'] ?? '')) ?: null;
                $taxId = trim((string) ($item['tax_id'] ?? '')) ?: null;
                $key = implode('|', [$date, $amount, BankValues::text($description), BankValues::text($reference), BankValues::rut($taxId)]);
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $hash = hash('sha256', $key . '|' . $seen[$key]);

                if (BankTransaction::query()->where('row_hash', $hash)->exists()) {
                    $counts['duplicated']++;
                    continue;
                }

                BankTransaction::create([
                    'import_id'        => $import->id,
                    'row_hash'         => $hash,
                    'posted_at'        => $date,
                    'amount'           => $amount,
                    'description'      => $description,
                    'reference'        => $reference ? mb_substr($reference, 0, 250) : null,
                    'tax_id'           => $taxId ? mb_substr($taxId, 0, 30) : null,
                    'counterpart_name' => ($name = trim((string) ($item['name'] ?? ''))) !== '' ? mb_substr($name, 0, 250) : null,
                    'raw'              => $item['raw'] ?? null,
                    'status'           => BankTransaction::UNMATCHED,
                    'trace_id'         => traceId() ?: null,
                ]);
                $counts['imported']++;
            }

            $import->update([
                'rows_total' => $counts['total'], 'rows_imported' => $counts['imported'],
                'rows_duplicated' => $counts['duplicated'], 'rows_skipped' => $counts['skipped'],
                'content' => null, 'processed_at' => now(),
            ]);

            $result = (new MatchBankTransactions($import->id))->execute();
            $import->update(['status' => 'done', 'matched' => $result['matched'], 'suggested' => $result['suggested']]);

            Activity::record('bank_import', $import->id, 'processed', null, $counts + $result + ['file' => $import->file_name], null, $import->created_by);
            BillingLog::info('bank statement imported', ['import_id' => $import->id] + $counts + $result);
        } catch (\Throwable $e) {
            report($e);
            $import->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            BillingLog::error('bank statement import failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
        }

        return $import->fresh();
    }
}
