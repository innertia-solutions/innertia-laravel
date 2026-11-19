<?php

namespace Innertia\Mail;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Innertia\Mail\Listeners\LogSentEmail;
use Innertia\Mail\Listeners\MarkFailedEmail;
use Innertia\Platform\Models\EmailLog;
use Throwable;

/**
 * Email log (table email_logs) for every email the product sends.
 *
 * Opt-in (products that still have their own listeners must not register both):
 *
 *   \Innertia\Mail\EmailLogging::listen();   // in the product provider boot()
 *
 * - queued(): writes the 'queued' row and links it to the mailable (metadata email_log_id).
 *   The Notifier uses it; products can use it for their own Mail::queue() calls.
 * - LogSentEmail (MessageSent): marks the linked row 'sent'; emails sent outside queued()
 *   get a 'sent' row with type (X-Metadata-innertia_type) and tenant — never 'unknown'.
 * - MarkFailedEmail (JobFailed): marks the row 'failed' with the reason once retries run out.
 */
final class EmailLogging
{
    private static bool $listening = false;

    private static ?bool $engineColumns = null;

    public static function listen(): void
    {
        if (self::$listening) {
            return;
        }

        self::$listening = true;
        Event::listen(MessageSent::class, LogSentEmail::class);
        Event::listen(JobFailed::class, MarkFailedEmail::class);
    }

    /** For tests: allows listen() to register again on a fresh dispatcher. */
    public static function reset(): void
    {
        self::$listening = false;
        self::$engineColumns = null;
    }

    /**
     * Creates the 'queued' row for $mailable sent to $to and links it (metadata email_log_id).
     */
    public static function queued(Mailable $mailable, string $to, ?string $userId = null, ?string $type = null, ?string $tenantId = null): ?EmailLog
    {
        try {
            $subject = method_exists($mailable, 'subjectLine') ? $mailable->subjectLine() : (string) $mailable->subject;

            $log = EmailLog::create(self::attributes([
                'user_id'        => $userId,
                'to'             => $to,
                'subject'        => mb_substr((string) $subject, 0, 255),
                'mailable_class' => get_class($mailable),
                'type'           => $type ?? self::typeOf($mailable),
                'tenant_id'      => $tenantId ?? (method_exists($mailable, 'mailTenantId') ? $mailable->mailTenantId() : null),
                'trace_id'       => self::traceId(),
                'status'         => 'queued',
                'created_at'     => now(),
            ]));

            $mailable->metadata('email_log_id', (string) $log->getKey());

            return $log;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Drops engine columns (type/tenant_id/trace_id) when the product has not migrated them yet. */
    public static function attributes(array $attributes): array
    {
        if (! self::hasEngineColumns()) {
            unset($attributes['type'], $attributes['tenant_id'], $attributes['trace_id']);
        }

        return $attributes;
    }

    public static function typeOf(object $mailable): string
    {
        if (method_exists($mailable, 'resolvedEmailType')) {
            return $mailable->resolvedEmailType();
        }

        return \Illuminate\Support\Str::kebab(class_basename($mailable));
    }

    /** Reads the email_log_id metadata (protected property of Mailable). */
    public static function emailLogId(Mailable $mailable): ?string
    {
        $value = (fn () => $this->metadata['email_log_id'] ?? null)->call($mailable);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function traceId(): ?string
    {
        $id = function_exists('traceId') ? traceId() : '';

        return $id !== '' ? $id : null;
    }

    private static function hasEngineColumns(): bool
    {
        if (self::$engineColumns === true) {
            return true;
        }

        try {
            $has = Schema::hasColumn('email_logs', 'type') && Schema::hasColumn('email_logs', 'tenant_id');
        } catch (Throwable) {
            return false;
        }

        if ($has) {
            self::$engineColumns = true;
        }

        return $has;
    }
}
