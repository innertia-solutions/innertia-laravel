<?php

namespace Innertia\Ai\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Innertia\Ai\Models\UsageEvent;

/**
 * Fired after every ai_usage_events row is stored (Ai::recordUsage() and the legacy
 * Ai::usage()->record()). Innertia\Billing listens to it to meter `ai.cost`.
 */
class AiUsageRecorded
{
    use Dispatchable;

    public function __construct(public readonly UsageEvent $event) {}
}
