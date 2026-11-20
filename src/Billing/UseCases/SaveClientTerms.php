<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\ClientTerms;
use Innertia\Platform\Contracts\UseCase;
use Innertia\Saas\Models\Client;

/**
 * Collection terms of a client: late fee percent (clients.late_fee_percent), basis (monthly|daily),
 * cap over the invoice total, exemption and payment reminders. Audited with before/after.
 */
class SaveClientTerms extends UseCase
{
    public const FIELDS = ['late_fee_basis', 'late_fee_cap_percent', 'late_fee_exempt', 'reminders_enabled'];

    public function __construct(public readonly string $clientId, public readonly array $data) {}

    public function execute(): array
    {
        $client = Client::query()->findOrFail($this->clientId);
        $terms = ClientTerms::for($this->clientId);
        $before = self::present($client, $terms);
        $userId = auth()->check() ? (string) auth()->id() : null;

        if (array_key_exists('late_fee_percent', $this->data)) {
            $client->update(['late_fee_percent' => (float) ($this->data['late_fee_percent'] ?? 0)]);
        }
        $terms->fill(array_intersect_key($this->data, array_flip(self::FIELDS)) + ['updated_by' => $userId])->save();

        $after = self::present($client->fresh(), $terms->fresh());
        $changes = ReissueInvoice::changes($before, $after);
        if ($changes !== []) {
            Activity::record('terms', $this->clientId, 'updated', $this->clientId, $changes, null, $userId);
        }

        return $after;
    }

    public static function present(Client $client, ClientTerms $terms): array
    {
        return [
            'client_id'            => (string) $client->id,
            'late_fee_percent'     => (float) ($client->late_fee_percent ?? 0),
            'late_fee_basis'       => $terms->late_fee_basis ?? 'monthly',
            'late_fee_cap_percent' => $terms->late_fee_cap_percent,
            'late_fee_exempt'      => (bool) $terms->late_fee_exempt,
            'reminders_enabled'    => (bool) ($terms->reminders_enabled ?? true),
        ];
    }
}
