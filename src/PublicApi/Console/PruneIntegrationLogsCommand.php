<?php

namespace Innertia\PublicApi\Console;

use Illuminate\Console\Command;
use Innertia\PublicApi\Models\ApiRequestLog;
use Innertia\PublicApi\Models\IdempotencyKey;
use Innertia\PublicApi\Support\IntegrationsSchema;
use Innertia\Webhooks\Models\WebhookLog;

/** Retention of the public API logs (default 30 days) + expired idempotency keys. Scheduled daily by the lib. */
class PruneIntegrationLogsCommand extends Command
{
    protected $signature = 'innertia:integrations:prune {--days= : Retention in days (default innertia.public_api.retention_days = 30)}';

    protected $description = 'Prune webhook delivery logs, API request logs and expired idempotency keys';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('innertia.public_api.retention_days', 30));
        $cutoff = now()->subDays($days);
        $counts = ['webhook_logs' => 0, 'api_request_logs' => 0, 'api_idempotency_keys' => 0];

        if (IntegrationsSchema::webhookLogsV2()) {
            $counts['webhook_logs'] = WebhookLog::query()->where('created_at', '<', $cutoff)->delete();
        }
        if (IntegrationsSchema::requestLogs()) {
            $counts['api_request_logs'] = ApiRequestLog::query()->where('created_at', '<', $cutoff)->delete();
        }
        if (IntegrationsSchema::idempotency()) {
            $counts['api_idempotency_keys'] = IdempotencyKey::query()->where('expires_at', '<', now())->delete();
        }

        $this->info(collect($counts)->map(fn ($n, $t) => "{$t}: {$n}")->implode(' · '));

        return self::SUCCESS;
    }
}
