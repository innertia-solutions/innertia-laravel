<?php

namespace Innertia\Webhooks;

use Innertia\Webhooks\Models\Webhook;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Webhooks\Jobs\DispatchWebhookJob;

class WebhookService
{
    public function dispatchForEvent(DomainEvent $event): void
    {
        $tenantId = $this->resolveTenantId();
        $eventKey = $event->resolvedKey();
        $payload  = array_merge($event->payload(), [
            '_event'     => $eventKey,
            '_timestamp' => now()->toIso8601String(),
        ]);

        $query = Webhook::query()->where('active', true);

        if (config('innertia.mode') === 'saas') {
            $query->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
            });
        }

        $query->get()
            ->filter(fn (Webhook $w) => $w->matchesEvent($eventKey))
            ->each(fn (Webhook $w) => DispatchWebhookJob::dispatch($w, $eventKey, $payload));
    }

    private function resolveTenantId(): mixed
    {
        return \Innertia\Facades\Innertia::tenant()?->getKey();
    }
}
