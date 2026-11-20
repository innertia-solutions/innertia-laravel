<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Models\BankTransaction;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\Support\TransactionMatcher;
use Innertia\Platform\Contracts\UseCase;

/**
 * Scores the open movements (of one import, or all) against the open invoices. Conclusive ones are
 * reconciled automatically (matched_by = 'auto'); the rest keep their suggestions for the manual screen.
 *
 * @return array{matched: int, suggested: int, unmatched: int}
 */
class MatchBankTransactions extends UseCase
{
    public int $timeout = 600;

    public function __construct(public readonly ?string $importId = null, public readonly bool $autoApply = true) {}

    public function execute(): array
    {
        BillingLog::ensureTrace();
        $counts = ['matched' => 0, 'suggested' => 0, 'unmatched' => 0];

        $transactions = BankTransaction::query()
            ->whereIn('status', [BankTransaction::UNMATCHED, BankTransaction::SUGGESTED])
            ->when($this->importId, fn ($q) => $q->where('import_id', $this->importId))
            ->orderBy('posted_at')->get();

        foreach ($transactions as $transaction) {
            // Fresh candidates each time: an auto-applied movement changes the open balances.
            $suggestions = (new TransactionMatcher)->suggest($transaction);

            if ($this->autoApply && TransactionMatcher::isConclusive($suggestions)) {
                $best = $suggestions[0];
                try {
                    (new ReconcileTransaction($transaction->id, $best['client_id'], $best['invoice_ids'], matchedBy: 'auto', score: $best['score'], suggestions: $suggestions))->execute();
                    $counts['matched']++;
                    continue;
                } catch (\Throwable $e) {
                    BillingLog::warning('auto reconciliation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
                }
            }

            $transaction->update([
                'suggestions' => $suggestions ?: null,
                'score'       => $suggestions[0]['score'] ?? null,
                'status'      => $suggestions ? BankTransaction::SUGGESTED : BankTransaction::UNMATCHED,
            ]);
            $counts[$suggestions ? 'suggested' : 'unmatched']++;
        }

        return $counts;
    }
}
