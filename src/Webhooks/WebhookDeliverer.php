<?php

namespace Innertia\Webhooks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Innertia\Notifications\Notice;
use Innertia\Notifications\Notifier;
use Innertia\Notifications\NoticeTypes;
use Innertia\Notifications\Recipients;
use Innertia\PublicApi\Support\IntegrationsSchema;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\Models\WebhookLog;
use Innertia\Webhooks\Security\UnsafeWebhookUrl;
use Innertia\Webhooks\Security\UrlGuard;
use Innertia\Webhooks\Signing\StandardWebhookSigner;
use Innertia\Webhooks\Support\WebhookTelemetry;
use Throwable;

/**
 * Performs ONE delivery attempt of a webhook message: anti-SSRF check (fresh DNS, connection pinned to the
 * checked IP, no redirects), Standard Webhooks + legacy signatures, 10 s timeout, response truncated to 64 KB,
 * log row, health tracking (continuous failure for 24 h → disable + `webhook.disabled` notice).
 */
class WebhookDeliverer
{
    public const MAX_RESPONSE_BYTES = 65536;

    public const NOTICE_DISABLED = 'webhook.disabled';

    public function __construct(private readonly UrlGuard $guard) {}

    public static function newMessageId(): string
    {
        return 'msg_' . strtolower((string) Str::ulid());
    }

    /**
     * @return array{ok: bool, retryable: bool, log: WebhookLog}
     */
    public function attempt(Webhook $webhook, string $eventKey, array $payload, string $messageId, int $attempt = 1, int $maxAttempts = 1, bool $isTest = false): array
    {
        $v2Logs = IntegrationsSchema::webhookLogsV2();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        $log = WebhookLog::create(array_filter([
            'webhook_id' => $webhook->id,
            'event_key'  => $eventKey,
            'payload'    => $payload,
            'attempts'   => $attempt,
        ] + ($v2Logs ? [
            'message_id' => $messageId,
            'status'     => WebhookLog::STATUS_PENDING,
            'is_test'    => $isTest,
            'trace_id'   => traceId() !== '' ? traceId() : null,
        ] : []), fn ($v) => $v !== null));

        $headers = [
            'Content-Type'         => 'application/json',
            'User-Agent'           => (string) config('innertia.webhooks.user_agent', 'Innertia-Webhooks/1.0'),
            'webhook-id'           => $messageId,
            'webhook-timestamp'    => (string) $timestamp,
            'webhook-signature'    => StandardWebhookSigner::header($webhook->signingSecrets(), $messageId, $timestamp, $body),
            'X-Innertia-Event'     => $eventKey,
            'X-Innertia-Signature' => 'sha256=' . hash_hmac('sha256', $body, $webhook->currentSecret()),
            'X-Innertia-Delivery'  => (string) $log->id,
        ];

        $context = ['webhook_id' => $webhook->id, 'message_id' => $messageId, 'event' => $eventKey, 'attempt' => $attempt, 'host' => parse_url((string) $webhook->url, PHP_URL_HOST)];

        try {
            $target = $this->guard->check((string) $webhook->url);
        } catch (UnsafeWebhookUrl $e) {
            $this->finish($log, $webhook, false, null, null, $e->getMessage(), 0, $attempt, $maxAttempts, false, $isTest, $headers, $v2Logs);
            WebhookTelemetry::warning('webhooks.blocked_url', $context + ['reason' => $e->getMessage()]);

            return ['ok' => false, 'retryable' => false, 'log' => $log->refresh()];
        }

        $start = hrtime(true);
        $status = null;
        $responseBody = null;
        $error = null;

        try {
            $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];

            $response = Http::timeout((int) config('innertia.webhooks.timeout', 10))
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withHeaders($headers)
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]]])
                ->withBody($body, 'application/json')
                ->post((string) $webhook->url);

            $status = $response->status();
            $responseBody = self::truncate($response->body());
            $ok = $response->successful();

            if (! $ok) {
                $error = $response->redirect() ? "Redirección no permitida (HTTP {$status})." : "El receptor respondió HTTP {$status}.";
            }
        } catch (Throwable $e) {
            $ok = false;
            $error = mb_substr($e->getMessage(), 0, 1000);
        }

        $ms = (int) round((hrtime(true) - $start) / 1e6);
        $this->finish($log, $webhook, $ok, $status, $responseBody, $error, $ms, $attempt, $maxAttempts, true, $isTest, $headers, $v2Logs);

        $ok
            ? WebhookTelemetry::info('webhooks.delivered', $context + ['status' => $status, 'ms' => $ms])
            : WebhookTelemetry::warning('webhooks.failed', $context + ['status' => $status, 'ms' => $ms, 'error' => $error]);

        return ['ok' => $ok, 'retryable' => ! $ok, 'log' => $log->refresh()];
    }

    /** Response body capped to 64 KB, always valid UTF-8 (text columns reject invalid bytes). */
    public static function truncate(string $body): string
    {
        $cut = mb_strcut($body, 0, self::MAX_RESPONSE_BYTES, 'UTF-8');

        return mb_check_encoding($cut, 'UTF-8') ? $cut : mb_scrub($cut, 'UTF-8');
    }

    /** Backoff (seconds) between attempts: 1 min, 5 min, 30 min, 2 h, 6 h, 12 h (~21 h of retries). */
    public static function backoff(): array
    {
        return array_map('intval', (array) config('innertia.webhooks.backoff', [60, 300, 1800, 7200, 21600, 43200]));
    }

    private function finish(WebhookLog $log, Webhook $webhook, bool $ok, ?int $status, ?string $body, ?string $error, int $ms, int $attempt, int $maxAttempts, bool $retryable, bool $isTest, array $headers, bool $v2Logs): void
    {
        $final = $ok || ! $retryable || $attempt >= $maxAttempts;
        $delay = self::backoff()[$attempt - 1] ?? null;

        $attributes = [
            'response_status' => $status,
            'response_body'   => $body ?? $error,
            'delivered_at'    => $ok ? now() : null,
            'failed_at'       => $ok ? null : now(),
        ];

        if ($v2Logs) {
            $attributes += [
                'status'          => $ok ? WebhookLog::STATUS_DELIVERED : ($final ? WebhookLog::STATUS_FAILED : WebhookLog::STATUS_RETRYING),
                'duration_ms'     => $ms,
                'error'           => $error,
                'request_headers' => self::safeHeaders($headers),
                'next_retry_at'   => (! $final && $delay !== null) ? now()->addSeconds($delay) : null,
            ];
        }

        $log->update($attributes);

        if (! $isTest) {
            $this->trackHealth($webhook, $ok);
        }
    }

    /** Request headers as stored in the log (signatures are not secrets; nothing else sensitive is sent). */
    private static function safeHeaders(array $headers): array
    {
        return $headers;
    }

    private function trackHealth(Webhook $webhook, bool $ok): void
    {
        if (! IntegrationsSchema::webhooksV2()) {
            return;
        }

        $webhook->refresh();

        if ($ok) {
            $webhook->forceFill(['failing_since' => null, 'last_success_at' => now()])->saveQuietly();

            return;
        }

        $webhook->forceFill(['failing_since' => $webhook->failing_since ?? now(), 'last_failure_at' => now()]);
        $hours = (int) config('innertia.webhooks.disable_after_hours', 24);

        if ($webhook->active && $webhook->failing_since->lte(now()->subHours($hours))) {
            $webhook->forceFill([
                'active'          => false,
                'disabled_at'     => now(),
                'disabled_reason' => "Desactivado automáticamente tras {$hours} h de fallas continuas.",
            ]);
            $webhook->saveQuietly();
            WebhookTelemetry::warning('webhooks.disabled', ['webhook_id' => $webhook->id, 'hours' => $hours]);
            $this->notifyDisabled($webhook, $hours);

            return;
        }

        $webhook->saveQuietly();
    }

    private function notifyDisabled(Webhook $webhook, int $hours): void
    {
        try {
            $recipients = collect();

            try {
                $recipients = Recipients::withPermission((string) config('innertia.webhooks.notify_permission', 'settings.manage'));
            } catch (Throwable) {
                // No RBAC tables (api mode, tests): only the creator.
            }

            $ids = $recipients->map(fn ($u) => (string) $u->getKey())->all();
            if (filled($webhook->created_by)) {
                $ids[] = (string) $webhook->created_by;
            }

            if (NoticeTypes::get(self::NOTICE_DISABLED) === null) {
                NoticeTypes::register(...NoticeTypes::integrationTypes());
            }

            Notifier::send(new Notice(
                type: self::NOTICE_DISABLED,
                title: 'Webhook desactivado',
                body: 'Se desactivó el webhook ' . parse_url((string) $webhook->url, PHP_URL_HOST) . " tras {$hours} h de fallas continuas. Revisa el receptor y vuelve a activarlo.",
                url: config('innertia.webhooks.manage_url'),
                recipients: array_values(array_unique($ids)),
                subjectKey: "webhook:{$webhook->id}",
                data: ['webhook_id' => $webhook->id],
                priority: Notice::PRIORITY_HIGH,
                tenantId: isset($webhook->tenant_id) ? (string) $webhook->tenant_id : null,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
