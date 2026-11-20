<?php

namespace Innertia\Billing\UseCases;

use Innertia\Billing\Enums\AdjustmentType;
use Innertia\Billing\Models\Activity;
use Innertia\Billing\Models\Adjustment;
use Innertia\Billing\Support\BillingLog;
use Innertia\Exceptions\ConflictException;
use Innertia\Platform\Contracts\UseCase;

/**
 * Creates an adjustment applied at the next closes:
 *   - of a space (tenantId): discount, surcharge, one_off or credit; amount (net, before VAT) or percent
 *     (over the charge before adjustments); recurring with validity (starts_at..ends_at) or once.
 *   - of a client (clientId, no space): only percent discounts/surcharges, applied to every space.
 * Reason and author are mandatory trail; every adjustment becomes a statement line with its reason.
 */
class CreateAdjustment extends UseCase
{
    public function __construct(
        public readonly AdjustmentType $type,
        public readonly string $reason,
        public readonly ?string $tenantId = null,
        public readonly ?string $clientId = null,
        public readonly ?int $amount = null,
        public readonly ?float $percent = null,
        public readonly bool $recurring = false,
        public readonly ?string $startsAt = null,
        public readonly ?string $endsAt = null,
        public readonly ?string $createdBy = null,
    ) {}

    public function execute(): Adjustment
    {
        BillingLog::ensureTrace();

        if (($this->amount === null) === ($this->percent === null)) {
            throw new ConflictException('Indica un monto o un porcentaje (uno de los dos).');
        }
        if (($this->amount !== null && $this->amount <= 0) || ($this->percent !== null && ($this->percent <= 0 || $this->percent > 100))) {
            throw new ConflictException('El monto o porcentaje no es válido.');
        }
        if ($this->startsAt && $this->endsAt && $this->endsAt < $this->startsAt) {
            throw new ConflictException('La vigencia termina antes de empezar.');
        }

        $clientId = $this->clientId;
        if ($this->tenantId === null) {
            if ($clientId === null) {
                throw new ConflictException('Indica el espacio o el cliente.');
            }
            if ($this->percent === null || ! in_array($this->type, [AdjustmentType::Discount, AdjustmentType::Surcharge], true)) {
                throw new ConflictException('A nivel de cliente solo se admiten descuentos o recargos porcentuales.');
            }
        } else {
            $model = config('innertia.saas.tenant_model') ?? \Innertia\Saas\Models\Tenant::class;
            $clientId = (string) ($model::query()->find($this->tenantId)?->client_id ?? $clientId);
        }

        $adjustment = Adjustment::create([
            'tenant_id'  => $this->tenantId,
            'client_id'  => $clientId ?: null,
            'type'       => $this->type,
            'amount'     => $this->amount,
            'percent'    => $this->percent,
            'recurring'  => $this->recurring && ! in_array($this->type, [AdjustmentType::OneOff, AdjustmentType::Credit], true),
            'starts_at'  => $this->startsAt,
            'ends_at'    => $this->endsAt,
            'reason'     => $this->reason,
            'target'     => 'next_period',
            'created_by' => $this->createdBy ?? (auth()->check() ? (string) auth()->id() : null),
            'trace_id'   => traceId() ?: null,
        ]);

        Activity::record('adjustment', $adjustment->id, 'created', $clientId ?: null, [
            'type' => $this->type->value, 'amount' => $this->amount, 'percent' => $this->percent, 'recurring' => $adjustment->recurring,
            'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt, 'reason' => $this->reason,
        ], $this->tenantId, $adjustment->created_by);
        BillingLog::info('adjustment created', ['adjustment_id' => $adjustment->id, 'tenant_id' => $this->tenantId, 'client_id' => $clientId, 'type' => $this->type->value]);

        return $adjustment;
    }
}
