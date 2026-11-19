<?php

namespace Innertia\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Innertia\Facades\Innertia;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\WebhookDeliverer;

/**
 * Delivers one webhook message (queue `webhooks`). Every attempt reuses the same `webhook-id` (receivers
 * dedupe by it). Retries: 1 min, 5 min, 30 min, 2 h, 6 h, 12 h. The last attempt records the final failure
 * without throwing (no failed_jobs noise); the sync queue never throws into the caller's request.
 */
class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // `$queue` comes from Queueable (redeclaring it typed was a fatal composition error): set in the constructor.
    public int    $tries   = 7;
    public int    $timeout = 30;

    /** The subscription may be deleted while a retry is pending. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        private readonly Webhook $webhook,
        private readonly string  $eventKey,
        private readonly array   $payload,
        private readonly ?string $messageId = null,
        private readonly bool    $isTest = false,
        private readonly ?string $traceId = null,
    ) {
        $this->onQueue((string) config('innertia.webhooks.queue', 'webhooks'));
    }

    public function backoff(): array
    {
        return WebhookDeliverer::backoff();
    }

    public function handle(WebhookDeliverer $deliverer): void
    {
        if ($this->traceId) {
            app()->instance('trace_id', $this->traceId);
        }

        $previous = Innertia::tenant();
        $tenantKey = $this->tenantKey();

        if ($tenantKey !== null) {
            Innertia::activate($tenantKey);
        }

        try {
            $attempt = max(1, $this->attempts());
            $result = $deliverer->attempt(
                $this->webhook,
                $this->eventKey,
                $this->payload,
                $this->messageId ?? 'msg_' . substr(sha1($this->webhook->id . $this->eventKey . json_encode($this->payload)), 0, 26),
                $attempt,
                $this->tries,
                $this->isTest,
            );
        } finally {
            if ($tenantKey !== null) {
                $previous ? app(\Innertia\Saas\TenantContext::class)->set($previous) : Innertia::deactivate();
            }
        }

        if (! $result['ok'] && $result['retryable'] && $attempt < $this->tries && ! $this->job instanceof SyncJob && $this->job !== null) {
            throw new \RuntimeException("Webhook {$this->webhook->id} delivery failed (attempt {$attempt}); retrying.");
        }
    }

    private function tenantKey(): ?string
    {
        if (! Innertia::tenancyEnabled() || blank($this->webhook->tenant_id ?? null)) {
            return null;
        }

        $model = config('innertia.saas.tenant_model', \Innertia\Saas\Models\Tenant::class);
        $tenant = $model::query()->whereKey($this->webhook->tenant_id)->first()
            ?? $model::query()->where('key', $this->webhook->tenant_id)->first();

        return $tenant?->key;
    }
}
