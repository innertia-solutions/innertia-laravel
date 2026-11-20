<?php

namespace Innertia\Billing\Support;

use Illuminate\Support\Collection;
use Innertia\Billing\Enums\InvoiceStatus;
use Innertia\Billing\Models\BankTransaction;
use Innertia\Billing\Models\Invoice;
use Innertia\Saas\Models\Client;

/**
 * Suggests which client and open invoices a bank movement pays, with a score 0–100:
 *
 *   RUT of the payer = client's RUT            +40  (RUT written in the description: +35)
 *   invoice number / folio in description/ref  +40
 *   client name words in description/name      +20 (+15 with one word)
 *   amount = balance of one invoice            +45  (= its total: +25)
 *   amount = sum of several open balances      +40  (the referenced ones, or all of the client)
 *   amount below the balance (partial payment) +5
 *
 * Auto-apply (MatchBankTransactions) when the best score ≥ innertia.billing.reconciliation.auto_score
 * (default 85) and it beats the second one by 15+. Candidates under min_score (30) are dropped.
 */
final class TransactionMatcher
{
    private const STOP_WORDS = ['spa', 'ltda', 'limitada', 'sociedad', 'comercial', 'servicios', 'empresa', 'chile', 'municipalidad', 'ilustre', 'corporacion', 'transferencia', 'de', 'del', 'la', 'los', 'las', 'y'];

    /** @var Collection<string, Collection<int, Invoice>> open invoices by client */
    private Collection $open;

    /** @var Collection<string, Client> */
    private Collection $clients;

    public function __construct()
    {
        $this->open = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::Overdue->value])
            ->where('balance', '>', 0)
            ->orderBy('due_at')->orderBy('number')
            ->get()->groupBy('client_id');
        $this->clients = Client::query()->whereIn('id', $this->open->keys())->get()->keyBy('id');
    }

    /** @return array<int, array{client_id: string, client_name: string, invoice_ids: string[], invoices: string[], amount: int, score: int, reasons: string[]}> */
    public function suggest(BankTransaction $t): array
    {
        $text = BankValues::text(implode(' ', [$t->description, $t->reference, $t->counterpart_name]));
        $haystack = ' ' . $text . ' ';
        $payerRut = BankValues::rut($t->tax_id);
        $rutsInText = BankValues::rutsIn(implode(' ', [$t->description, $t->reference]));

        $suggestions = [];
        foreach ($this->open as $clientId => $invoices) {
            $client = $this->clients->get($clientId);
            if (! $client) {
                continue;
            }

            $score = 0;
            $reasons = [];
            $clientRut = BankValues::rut($client->tax_id);
            if ($clientRut !== '' && $payerRut !== '' && $payerRut === $clientRut) {
                $score += 40;
                $reasons[] = 'tax_id';
            } elseif ($clientRut !== '' && in_array($clientRut, $rutsInText, true)) {
                $score += 35;
                $reasons[] = 'tax_id_in_text';
            }

            $words = $this->nameWords($client);
            $hits = count(array_filter($words, fn ($w) => str_contains($haystack, " {$w} ")));
            if ($hits > 0) {
                $score += $hits >= 2 ? 20 : 15;
                $reasons[] = 'name';
            }

            $referenced = $invoices->filter(fn (Invoice $i) => $this->mentions($haystack, $i))->values();
            if ($referenced->isNotEmpty()) {
                $score += 40;
                $reasons[] = 'reference';
            }

            [$selected, $amountScore, $amountReason] = $this->byAmount($t->amount, $invoices, $referenced);
            $score += $amountScore;
            if ($amountReason) {
                $reasons[] = $amountReason;
            }

            if ($selected->isEmpty()) {
                $selected = $referenced->isNotEmpty() ? $referenced : $invoices;
            }
            $score = min(100, $score);
            if ($score < (int) config('innertia.billing.reconciliation.min_score', 30)) {
                continue;
            }

            $suggestions[] = [
                'client_id'   => (string) $clientId,
                'client_name' => (string) $client->name,
                'invoice_ids' => $selected->pluck('id')->values()->all(),
                'invoices'    => $selected->map(fn (Invoice $i) => $i->displayNumber())->values()->all(),
                'amount'      => min($t->amount, (int) $selected->sum('balance')),
                'score'       => $score,
                'reasons'     => $reasons,
            ];
        }

        usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($suggestions, 0, 3);
    }

    /** True when the best suggestion is good and clear enough to apply alone. */
    public static function isConclusive(array $suggestions): bool
    {
        $best = $suggestions[0]['score'] ?? 0;
        $second = $suggestions[1]['score'] ?? 0;

        return $best >= (int) config('innertia.billing.reconciliation.auto_score', 85) && $best - $second >= 15;
    }

    /** @return array{0: Collection, 1: int, 2: ?string} */
    private function byAmount(int $amount, Collection $invoices, Collection $referenced): array
    {
        $exact = $invoices->filter(fn (Invoice $i) => $i->balance === $amount);
        if ($exact->count() >= 1) {
            $pick = $exact->first(fn ($i) => $referenced->contains('id', $i->id)) ?? $exact->first();

            return [collect([$pick]), 45, 'amount_exact'];
        }
        if ($referenced->count() > 1 && (int) $referenced->sum('balance') === $amount) {
            return [$referenced, 40, 'amount_sum'];
        }
        if ($invoices->count() > 1 && (int) $invoices->sum('balance') === $amount) {
            return [$invoices, 40, 'amount_sum'];
        }
        $total = $invoices->first(fn (Invoice $i) => $i->total === $amount);
        if ($total) {
            return [collect([$total]), 25, 'amount_total'];
        }
        $target = $referenced->isNotEmpty() ? $referenced : $invoices;
        if ($amount < (int) $target->sum('balance')) {
            return [$target, 5, 'partial'];
        }

        return [collect(), 0, null];
    }

    private function mentions(string $haystack, Invoice $invoice): bool
    {
        $candidates = array_filter([
            $invoice->displayNumber() ? BankValues::text($invoice->displayNumber()) : null,      // "f 000123"
            $invoice->number ? 'f' . $invoice->number : null,
            $invoice->number ? 'factura ' . $invoice->number : null,
            $invoice->number ? str_pad((string) $invoice->number, 6, '0', STR_PAD_LEFT) : null,
            $invoice->folio ? BankValues::text($invoice->folio) : null,
        ]);
        foreach ($candidates as $needle) {
            if ($needle !== '' && str_contains($haystack, " {$needle} ")) {
                return true;
            }
        }

        return false;
    }

    private function nameWords(Client $client): array
    {
        $words = [];
        foreach ([$client->name, $client->legal_name] as $name) {
            foreach (explode(' ', BankValues::text($name)) as $w) {
                if (strlen($w) >= 4 && ! in_array($w, self::STOP_WORDS, true)) {
                    $words[$w] = true;
                }
            }
        }

        return array_keys($words);
    }
}
