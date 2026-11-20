<?php

namespace Innertia\Billing\Listeners;

use Innertia\Ai\Events\AiUsageRecorded;
use Innertia\Billing\Meters;
use Innertia\Billing\Support\BillingLog;
use Innertia\Billing\Support\ExchangeRate;

/**
 * Every priced AI usage of a space → `ai.cost` record (quantity = USD, cost = CLP at the platform
 * USD→CLP rate). Idempotent by the usage event id. Billing never breaks an AI call.
 */
class RecordAiCost
{
    public function handle(AiUsageRecorded $recorded): void
    {
        $event = $recorded->event;
        $usd = $event->cost_usd;

        if ($usd === null || $usd <= 0 || blank($event->tenant_id)) {
            return;
        }

        try {
            Meters::record((string) $event->tenant_id, Meters::AI_COST, (float) $usd, $event->created_at, [
                'feature' => $event->feature ?? $event->use,
                'model'   => $event->model,
            ], sourceRef: 'ai:' . $event->id, cost: ExchangeRate::toMinor((float) $usd));
        } catch (\Throwable $e) {
            BillingLog::warning('ai.cost meter failed', ['ai_usage_event_id' => $event->id, 'error' => $e->getMessage()]);
        }
    }
}
