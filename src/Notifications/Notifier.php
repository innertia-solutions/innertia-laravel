<?php

namespace Innertia\Notifications;

use Innertia\Notifications\Models\Notification;
use Innertia\Notifications\Models\NoticeState;
use Innertia\Notifications\Support\NotifyLog;
use Innertia\Notifications\UseCases\DeliverNotice;

/**
 * Single delivery point for notices (bell + email according to preferences).
 *
 *   Notifier::send(new Notice(type: 'central.offline', title: 'Central sin conexión',
 *       recipients: Recipients::withPermission('phone.manage'), subjectKey: "central:{$id}"));
 *
 *   Notifier::resolve('central.offline', "central:{$id}");   // recovery closes the open notice
 *
 * send() queues a DeliverNotice (queue config('innertia.notifications.queue', 'use-cases'))
 * carrying the tenant and the trace_id. sendNow() delivers synchronously.
 */
final class Notifier
{
    public static function send(Notice $notice): void
    {
        if ($notice->recipients === []) {
            NotifyLog::info('notice without recipients', ['type' => $notice->type, 'subject_key' => $notice->subjectKey]);

            return;
        }

        (new DeliverNotice($notice))->onQueue(config('innertia.notifications.queue', 'use-cases'));
    }

    /** @return array{bell: int, mail: int, grouped: int, skipped: int} */
    public static function sendNow(Notice $notice): array
    {
        return (new DeliverNotice($notice))->execute();
    }

    /**
     * Closes the open notices of (type, subjectKey) — e.g. the central came back online.
     * Their bell items are marked read; the next send() opens a new notice.
     *
     * @return int closed notices
     */
    public static function resolve(string $type, string $subjectKey, ?string $tenantId = null): int
    {
        $tenantId ??= self::activeTenantId();

        $states = NoticeState::query()
            ->where('type', $type)
            ->where('subject_key', $subjectKey)
            ->whereNull('resolved_at')
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $tenantId))
            ->get();

        foreach ($states as $state) {
            $state->update(['resolved_at' => now()]);

            if ($state->notification_id) {
                Notification::query()->whereKey($state->notification_id)->whereNull('read_at')->update(['read_at' => now()]);
            }
        }

        if ($states->isNotEmpty()) {
            NotifyLog::info('notice resolved', ['type' => $type, 'subject_key' => $subjectKey, 'closed' => $states->count()]);
        }

        return $states->count();
    }

    private static function activeTenantId(): ?string
    {
        try {
            $id = \Innertia\Facades\Innertia::tenancyEnabled() ? \Innertia\Facades\Innertia::tenant()?->getKey() : null;

            return filled($id) ? (string) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
