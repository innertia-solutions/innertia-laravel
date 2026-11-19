<?php

namespace Innertia\Notifications;

use Illuminate\Support\Collection;
use Innertia\Facades\Innertia;
use Innertia\Mail\NotificationMail;

/**
 * A notice to deliver through the Notifier.
 *
 *   Notifier::send(new Notice(
 *       type: 'voicemail.new',
 *       title: 'Nuevo mensaje de voz',
 *       body: 'Anexo 1203 · 0:42',
 *       url: '/backoffice/voicemail/123',
 *       recipients: Recipients::users([$owner]),
 *       subjectKey: 'voicemail:123',
 *       data: ['voicemail_id' => 123],
 *       mail: NotificationMail::make()->...,   // optional; default built from title/body/url
 *   ));
 *
 * recipients: users (models), ids, or a Collection of them; normalized to string ids here so the
 * notice serializes cleanly into the queue. The active tenant and trace_id are captured too.
 */
final class Notice
{
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    /** @var string[] */
    public readonly array $recipients;

    public readonly ?string $tenantId;

    public readonly ?string $traceId;

    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $body = '',
        public readonly ?string $url = null,
        iterable $recipients = [],
        public readonly ?string $subjectKey = null,
        public readonly array $data = [],
        public readonly string $priority = self::PRIORITY_NORMAL,
        public readonly ?NotificationMail $mail = null,
        ?string $tenantId = null,
        public readonly ?string $actionLabel = null,
    ) {
        $this->recipients = self::normalizeRecipients($recipients);
        $this->tenantId = $tenantId ?? self::activeTenantId();
        $id = function_exists('traceId') ? traceId() : '';
        $this->traceId = $id !== '' ? $id : null;
    }

    /** @return string[] */
    public static function normalizeRecipients(iterable $recipients): array
    {
        $ids = [];

        foreach ($recipients instanceof Collection ? $recipients->all() : $recipients as $recipient) {
            if ($recipient === null || $recipient === '') {
                continue;
            }

            $id = is_object($recipient)
                ? (method_exists($recipient, 'getKey') ? $recipient->getKey() : ($recipient->id ?? null))
                : $recipient;

            if ($id !== null && $id !== '') {
                $ids[(string) $id] = true;
            }
        }

        return array_keys($ids);
    }

    private static function activeTenantId(): ?string
    {
        try {
            $id = Innertia::tenancyEnabled() ? Innertia::tenant()?->getKey() : null;

            return filled($id) ? (string) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
