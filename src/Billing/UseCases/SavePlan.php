<?php

namespace Innertia\Billing\UseCases;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Innertia\Billing\Meters;
use Innertia\Billing\Models\Plan;
use Innertia\Billing\Support\BillingLog;
use Innertia\Platform\Contracts\UseCase;

/**
 * Creates or updates a plan and (when `meters` is given) syncs its metered prices.
 * Changes apply to the periods closed from now on (statements keep their own lines).
 */
class SavePlan extends UseCase
{
    public const FIELDS = [
        'key', 'name', 'description', 'product', 'currency', 'interval', 'base_price', 'included_seats',
        'seat_price', 'trial_days', 'tax_rate', 'limits', 'active',
    ];

    public function __construct(public readonly array $data, public readonly ?Plan $plan = null) {}

    public function execute(): Plan
    {
        return DB::transaction(function () {
            $attributes = Arr::only($this->data, self::FIELDS);
            if ($this->plan === null) {
                $attributes['currency'] ??= config('innertia.billing.currency', 'CLP');
            }

            $plan = $this->plan ?? new Plan;
            $plan->fill($attributes)->save();

            if (array_key_exists('meters', $this->data)) {
                $keys = [];
                foreach ((array) $this->data['meters'] as $row) {
                    if (Meters::get($row['meter']) === null) {
                        throw new \InvalidArgumentException("Unknown billing meter \"{$row['meter']}\".");
                    }
                    $keys[] = $row['meter'];
                    $plan->meters()->updateOrCreate(['meter' => $row['meter']], [
                        'included_quantity' => $row['included_quantity'] ?? 0,
                        'unit_price'        => $row['unit_price'] ?? 0,
                        'markup_percent'    => $row['markup_percent'] ?? null,
                    ]);
                }
                $plan->meters()->whereNotIn('meter', $keys)->delete();
            }

            BillingLog::info('plan saved', ['plan_id' => $plan->id, 'key' => $plan->key]);

            return $plan->load('meters');
        });
    }
}
