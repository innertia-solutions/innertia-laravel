<?php

namespace Innertia\Notifications\UseCases;

use Illuminate\Support\Facades\Mail;
use Innertia\Mail\EmailLogging;
use Innertia\Mail\NotificationMail;
use Innertia\Notifications\Models\Notification;
use Innertia\Notifications\Models\NoticeState;
use Innertia\Notifications\Notice;
use Innertia\Notifications\NoticeType;
use Innertia\Notifications\NoticeTypes;
use Innertia\Notifications\Preferences;
use Innertia\Notifications\Recipients;
use Innertia\Notifications\Support\NotifyLog;
use Innertia\Platform\Contracts\UseCase;
use Throwable;

/**
 * Delivers a Notice to its recipients (runs in the queue via Notifier::send()).
 *
 * Per recipient: preferences per channel (critical types ignore them) → grouping/debounce on
 * the open NoticeState of (tenant, type, subjectKey, user) → bell item (user_notifications) →
 * email (NotificationMail with the tenant brand, 'queued' row in email_logs, 3 retries with
 * backoff; MarkFailedEmail marks it 'failed' with the reason).
 */
class DeliverNotice extends UseCase
{
    public int $tries = 3;

    public function __construct(public readonly Notice $notice) {}

    /** @return array{bell: int, mail: int, grouped: int, skipped: int} */
    public function execute(): array
    {
        $this->bindTraceId();

        $notice = $this->notice;
        $type = NoticeTypes::resolve($notice->type);
        $stats = ['bell' => 0, 'mail' => 0, 'grouped' => 0, 'skipped' => 0];

        foreach (Recipients::users($notice->recipients) as $user) {
            $uid = (string) $user->getKey();

            try {
                $result = $this->deliverTo($user, $uid, $type);
                foreach ($result as $key => $n) {
                    $stats[$key] += $n;
                }
            } catch (Throwable $e) {
                report($e);
                NotifyLog::warning('notice delivery failed', ['type' => $notice->type, 'user_id' => $uid, 'error' => $e->getMessage()]);
            }
        }

        NotifyLog::info('notice delivered', ['type' => $notice->type, 'subject_key' => $notice->subjectKey, 'tenant_id' => $notice->tenantId] + $stats);

        return $stats;
    }

    private function deliverTo(object $user, string $uid, NoticeType $type): array
    {
        $notice = $this->notice;
        $wantsBell = Preferences::allows($uid, $type, NoticeType::BELL);
        $wantsMail = Preferences::allows($uid, $type, NoticeType::MAIL);

        if (! $wantsBell && ! $wantsMail) {
            return ['skipped' => 1];
        }

        $state = $this->usesState($type) ? $this->openState($uid) : null;

        if ($state && $type->group && $state->last_sent_at?->gte(now()->subMinutes($type->groupWindowMinutes))) {
            $this->groupInto($state, $type);

            return ['grouped' => 1];
        }

        if ($state && $type->debounceMinutes > 0 && $state->last_sent_at?->gte(now()->subMinutes($type->debounceMinutes))) {
            return ['skipped' => 1];
        }

        $notification = $wantsBell ? $this->createBell($uid) : null;
        $mailed = $wantsMail && filled($user->email ?? null) ? $this->queueMail($user, $uid) : false;

        if ($this->usesState($type)) {
            $attributes = ['notification_id' => $notification?->getKey(), 'count' => 1, 'last_sent_at' => now()];

            $state
                ? $state->update($attributes)
                : NoticeState::create($attributes + $this->stateKey($uid));
        }

        return ['bell' => $notification ? 1 : 0, 'mail' => $mailed ? 1 : 0];
    }

    private function usesState(NoticeType $type): bool
    {
        return $this->notice->subjectKey !== null || $type->debounceMinutes > 0 || $type->group;
    }

    private function stateKey(string $uid): array
    {
        return [
            'tenant_id'   => $this->notice->tenantId,
            'type'        => $this->notice->type,
            'subject_key' => (string) ($this->notice->subjectKey ?? ''),
            'user_id'     => $uid,
        ];
    }

    private function openState(string $uid): ?NoticeState
    {
        $key = $this->stateKey($uid);

        return NoticeState::query()
            ->where('type', $key['type'])
            ->where('subject_key', $key['subject_key'])
            ->where('user_id', $uid)
            ->when($key['tenant_id'] === null, fn ($q) => $q->whereNull('tenant_id'), fn ($q) => $q->where('tenant_id', $key['tenant_id']))
            ->whereNull('resolved_at')
            ->latest('last_sent_at')
            ->first();
    }

    private function groupInto(NoticeState $state, NoticeType $type): void
    {
        $count = $state->count + 1;
        $state->update(['count' => $count, 'last_sent_at' => now()]);

        $notification = $state->notification_id ? Notification::query()->find($state->notification_id) : null;

        if ($notification) {
            $data = $notification->data ?? [];
            $data['count'] = $count;
            $notification->update([
                'title'      => $type->titleForCount($count, $this->notice->title),
                'body'       => $this->notice->body,
                'data'       => $data,
                'read_at'    => null,
                'created_at' => now(),
            ]);
        }
    }

    private function createBell(string $uid): Notification
    {
        $notice = $this->notice;

        return Notification::create([
            'user_id'    => $uid,
            'type'       => $notice->type,
            'key'        => $notice->type,
            'title'      => $notice->title,
            'body'       => $notice->body,
            'data'       => array_filter([
                'url'         => $notice->url,
                'link'        => $notice->url,
                'priority'    => $notice->priority,
                'tenant_id'   => $notice->tenantId,
                'subject_key' => $notice->subjectKey,
                'trace_id'    => $notice->traceId,
                'count'       => 1,
            ], fn ($v) => $v !== null) + $notice->data,
            'created_at' => now(),
        ]);
    }

    private function queueMail(object $user, string $uid): bool
    {
        $notice = $this->notice;
        $mail = $notice->mail ? clone $notice->mail : $this->defaultMail();

        $mail->type($notice->type)->retries(
            (int) config('innertia.notifications.mail_tries', 3),
            config('innertia.notifications.mail_backoff', [60, 300, 900]),
        );

        if ($notice->tenantId !== null) {
            $mail->forTenant($notice->tenantId);
        }

        EmailLogging::queued($mail, (string) $user->email, $uid, $notice->type, $notice->tenantId);
        Mail::to((string) $user->email)->queue($mail);

        return true;
    }

    private function defaultMail(): NotificationMail
    {
        $notice = $this->notice;
        $mail = NotificationMail::make()->withSubject($notice->title)->title($notice->title);

        if ($notice->body !== '') {
            $mail->line(e($notice->body));
        }

        if ($notice->url) {
            $mail->action($notice->actionLabel ?? 'Ver detalle', self::absoluteUrl($notice->url));
        }

        return $mail;
    }

    public static function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        return $base . '/' . ltrim($url, '/');
    }

    private function bindTraceId(): void
    {
        $current = function_exists('traceId') ? traceId() : '';

        if ($current === '' && $this->notice->traceId) {
            app()->instance('trace_id', $this->notice->traceId);
        }
    }
}
