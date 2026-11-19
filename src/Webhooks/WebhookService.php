<?php

namespace Innertia\Webhooks;

use Innertia\Platform\Events\DomainEvent;
use Innertia\PublicApi\Support\IntegrationsSchema;
use Innertia\Webhooks\Jobs\DispatchWebhookJob;
use Innertia\Webhooks\Models\Webhook;

class WebhookService
{
    /**
     * Fan-out of a DomainEvent with 'webhook' in channels() to the matching subscriptions (exact key, '*',
     * 'family.*'), honouring the subscription's sede and granted scopes. Queued after the DB commit.
     *
     *   v2 subscriptions → `{type, timestamp, data}` with data = $event->webhookPayload($scopes) ?? payload()
     *   legacy rows      → the legacy body (payload + _event + _timestamp)
     */
    public function dispatchForEvent(DomainEvent $event): void
    {
        $eventKey = $event->resolvedKey();
        $timestamp = now()->toIso8601String();
        $organizationId = $event->webhookOrganizationId();
        $legacyBody = null;
        $dataByScopes = [];

        foreach ($this->subscriptionsFor($eventKey) as $webhook) {
            if ($webhook->excludesOrganization($organizationId)) {
                continue;
            }

            if ($webhook->isLegacy()) {
                $legacyBody ??= array_merge($event->payload(), ['_event' => $eventKey, '_timestamp' => $timestamp]);
                $this->queue($webhook, $eventKey, $legacyBody);

                continue;
            }

            $scopes = $webhook->grantedScopes();
            sort($scopes);
            $cacheKey = implode(',', $scopes);
            $dataByScopes[$cacheKey] ??= $event->webhookPayload($scopes) ?? $event->payload();

            $this->queue($webhook, $eventKey, ['type' => $eventKey, 'timestamp' => $timestamp, 'data' => $dataByScopes[$cacheKey]]);
        }
    }

    /**
     * Queues one message to one subscription (after commit). Returns the `webhook-id`.
     */
    public function queue(Webhook $webhook, string $eventKey, array $body, ?string $messageId = null, bool $isTest = false): string
    {
        $messageId ??= WebhookDeliverer::newMessageId();
        $traceId = traceId();

        DispatchWebhookJob::dispatch($webhook, $eventKey, $body, $messageId, $isTest, $traceId !== '' ? $traceId : null)
            ->onQueue((string) config('innertia.webhooks.queue', 'webhooks'))
            ->afterCommit();

        return $messageId;
    }

    /** @return \Illuminate\Support\Collection<int, Webhook> */
    private function subscriptionsFor(string $eventKey)
    {
        $query = Webhook::query()->where('active', true);

        if (\Innertia\Facades\Innertia::tenancyEnabled()) {
            $tenantId = \Innertia\Facades\Innertia::tenant()?->getKey();
            $query->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            });
        }

        if (IntegrationsSchema::webhooksV2()) {
            $query->whereNull('disabled_at');
        }

        return $query->get()->filter(fn (Webhook $w) => $w->matchesEvent($eventKey))->values();
    }
}
