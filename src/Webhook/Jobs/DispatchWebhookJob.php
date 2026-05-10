<?php

namespace Innertia\Webhook\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Innertia\Models\Webhook;
use Innertia\Models\WebhookLog;

class DispatchWebhookJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public string $queue   = 'webhooks';
    public int    $tries   = 3;
    public int    $timeout = 30;

    public function __construct(
        private readonly Webhook $webhook,
        private readonly string  $eventKey,
        private readonly array   $payload,
    ) {}

    public function backoff(): array
    {
        return [60, 300, 1800]; // 1 min, 5 min, 30 min
    }

    public function handle(): void
    {
        $body      = json_encode($this->payload, JSON_UNESCAPED_UNICODE);
        $signature = $this->sign($body);

        $log = WebhookLog::create([
            'webhook_id' => $this->webhook->id,
            'event_key'  => $this->eventKey,
            'payload'    => $this->payload,
            'attempts'   => $this->attempts(),
        ]);

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type'          => 'application/json',
                    'X-Innertia-Event'      => $this->eventKey,
                    'X-Innertia-Signature'  => 'sha256=' . $signature,
                    'X-Innertia-Delivery'   => $log->id,
                ])
                ->send('POST', $this->webhook->url, ['body' => $body]);

            $log->update([
                'response_status' => $response->status(),
                'response_body'   => substr($response->body(), 0, 2000),
                'delivered_at'    => $response->successful() ? now() : null,
                'failed_at'       => $response->successful() ? null : now(),
            ]);

            if (! $response->successful()) {
                $this->fail(new \RuntimeException(
                    "Webhook {$this->webhook->id} returned HTTP {$response->status()}"
                ));
            }
        } catch (\Throwable $e) {
            $log->update(['failed_at' => now(), 'response_body' => $e->getMessage()]);
            throw $e;
        }
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->webhook->secret);
    }
}
