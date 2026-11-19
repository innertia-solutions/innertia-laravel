<?php

namespace Innertia\Mail\Listeners;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Innertia\Mail\EmailLogging;
use Innertia\Platform\Models\EmailLog;
use Throwable;

/**
 * Marks the email row 'failed' (with the reason) when the queued email ran out of retries
 * (provider down, quota, credentials…). Without a queued row (mail queued outside
 * EmailLogging::queued()) it records a 'failed' row so /platform/emails shows it.
 */
class MarkFailedEmail
{
    public function handle(JobFailed $event): void
    {
        try {
            $data = $event->job->payload()['data'] ?? [];
            if (($data['commandName'] ?? null) !== SendQueuedMailable::class) {
                return;
            }

            $command = $data['command'] ?? null;
            $job = is_string($command) ? unserialize($command) : null;
            $mailable = $job instanceof SendQueuedMailable ? $job->mailable : null;

            if (! $mailable instanceof Mailable) {
                return;
            }

            $reason = mb_substr($event->exception->getMessage(), 0, 2000);
            $row = $this->findRow($mailable);

            if ($row) {
                $row->update(['status' => 'failed', 'exception' => $reason]);

                return;
            }

            $to = $mailable->to[0]['address'] ?? null;
            if ($to === null) {
                return;
            }

            EmailLog::create(EmailLogging::attributes([
                'to'             => $to,
                'subject'        => mb_substr((string) (method_exists($mailable, 'subjectLine') ? $mailable->subjectLine() : $mailable->subject), 0, 255),
                'mailable_class' => get_class($mailable),
                'type'           => EmailLogging::typeOf($mailable),
                'tenant_id'      => method_exists($mailable, 'mailTenantId') ? $mailable->mailTenantId() : null,
                'trace_id'       => EmailLogging::traceId(),
                'status'         => 'failed',
                'exception'      => $reason,
                'created_at'     => now(),
            ]));
        } catch (Throwable $e) {
            // Never break the queue failure handling because of the log.
            report($e);
        }
    }

    private function findRow(Mailable $mailable): ?EmailLog
    {
        $id = EmailLogging::emailLogId($mailable);

        if ($id !== null) {
            return EmailLog::whereKey($id)->where('status', 'queued')->first();
        }

        $to = $mailable->to[0]['address'] ?? null;
        if ($to === null) {
            return null;
        }

        $subject = method_exists($mailable, 'subjectLine') ? $mailable->subjectLine() : $mailable->subject;

        return EmailLog::where('to', $to)
            ->where('status', 'queued')
            ->where('mailable_class', get_class($mailable))
            ->where('created_at', '>=', now()->subDays(2))
            ->when($subject !== null, fn ($q) => $q->where('subject', $subject))
            ->latest('created_at')
            ->first();
    }
}
