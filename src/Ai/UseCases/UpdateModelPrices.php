<?php

namespace Innertia\Ai\UseCases;

use Illuminate\Support\Arr;
use Innertia\Ai\Models\AiModel;
use Innertia\Facades\Telemetry;
use Innertia\Platform\Contracts\UseCase;

/**
 * Sets the USD prices of a model (null = not priced). Affects usages recorded from now on:
 * cost_usd is computed when the usage is stored and never recalculated.
 */
class UpdateModelPrices extends UseCase
{
    public function __construct(public readonly AiModel $model, public readonly array $prices) {}

    public function execute(): AiModel
    {
        $before = $this->model->prices();
        $this->model->update(Arr::only($this->prices, AiModel::PRICE_FIELDS));

        try {
            Telemetry::log('operational', 'info', 'ai model prices updated', [
                'module' => 'ai', 'model' => $this->model->key, 'before' => $before,
                'after' => $this->model->prices(), 'trace_id' => traceId(),
            ]);
        } catch (\Throwable) {
            // Telemetry never breaks a write.
        }

        return $this->model->refresh();
    }
}
