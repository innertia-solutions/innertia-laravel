<?php

namespace Innertia\Mail\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Innertia\Mail\EmailLogging;
use Innertia\Platform\Models\EmailLog;
use Throwable;

/**
 * Marks as 'sent' the row created by EmailLogging::queued() (found by the email_log_id
 * metadata, or by recipient + subject within 2 days). Emails sent without a queued row get a
 * 'sent' row with the mailable class, type and tenant read from the message metadata.
 */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        try {
            $this->log($event);
        } catch (Throwable $e) {
            // The email already left: the log never breaks a delivery.
            report($e);
        }
    }

    private function log(MessageSent $event): void
    {
        $addresses = $event->message->getTo() ?? [];
        $to = count($addresses) > 0 ? $addresses[0]->getAddress() : null;

        if ($to === null) {
            return;
        }

        $subject = (string) ($event->message->getSubject() ?? '');
        $headers = $event->message->getHeaders();
        $meta = fn (string $name) => $headers->get('X-Metadata-' . $name)?->getBodyAsString();

        $id = $meta('email_log_id');
        $row = $id ? EmailLog::whereKey($id)->where('status', 'queued')->first() : null;

        $row ??= EmailLog::where('to', $to)
            ->where('status', 'queued')
            ->where('subject', $subject)
            ->where('created_at', '>=', now()->subDays(2))
            ->latest('created_at')
            ->first();

        if ($row) {
            $row->update(['status' => 'sent', 'sent_at' => now()]);

            return;
        }

        $class = $event->data['__laravel_mailable'] ?? null;

        EmailLog::create(EmailLogging::attributes([
            'to'             => $to,
            'subject'        => mb_substr($subject, 0, 255),
            'mailable_class' => $class ?? 'unknown',
            'type'           => $meta('innertia_type') ?? ($class ? \Illuminate\Support\Str::kebab(class_basename($class)) : null),
            'tenant_id'      => $meta('innertia_tenant'),
            'trace_id'       => EmailLogging::traceId(),
            'status'         => 'sent',
            'sent_at'        => now(),
            'created_at'     => now(),
        ]));
    }
}
