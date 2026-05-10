<?php

namespace Innertia\Webhook;

use Innertia\Models\Webhook;
use Innertia\Platform\Events\DomainEvent;
use Innertia\Webhook\Jobs\DispatchWebhookJob;

class WebhookService
{
    public function dispatchForEvent(DomainEvent $event): void
    {
        $tenantId = $this->resolveTenantId();
        $eventKey = $event->webhookKey();
        $payload  = array_merge($event->payload(), [
            '_event'     => $eventKey,
            '_timestamp' => now()->toIso8601String(),
        ]);

        Webhook::query()
            ->where('active', true)
            ->where(function ($q) use ($tenantId) {
                $q->whereNull('tenant_id');
                if ($tenantId !== null) {
                    $q->orWhere('tenant_id', $tenantId);
                }
            })
            ->get()
            ->filter(fn (Webhook $w) => $w->matchesEvent($eventKey))
            ->each(fn (Webhook $w) => DispatchWebhookJob::dispatch($w, $eventKey, $payload));
    }

    private function resolveTenantId(): mixed
    {
        return config('innertia.mode') === 'saas' && function_exists('tenant')
            ? tenant('id')
            : null;
    }
}
