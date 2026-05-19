<?php

namespace Innertia\Telemetry;

use Illuminate\Support\Facades\Http;

class TelemetryExporter
{
    public function __construct(
        private readonly ?string $olimpoUrl,
        private readonly ?string $olimpoKey,
        private readonly string  $appName,
        private readonly int     $timeout = 3,
    ) {}

    /** @param TelemetryEvent[] $batch */
    public function flush(array $batch, string $sessionId = 'unknown'): void
    {
        if (empty($batch) || !$this->olimpoUrl || !$this->olimpoKey) {
            return;
        }

        $payload = [
            'app'        => $this->appName,
            'session_id' => $sessionId,
            'events'     => array_map(fn (TelemetryEvent $e) => $e->toArray(), $batch),
        ];

        try {
            Http::timeout($this->timeout)
                ->withHeader('X-Olimpo-Key', $this->olimpoKey)
                ->post(rtrim($this->olimpoUrl, '/') . '/olimpo/telemetry', $payload);
        } catch (\Throwable) {
            // Silencioso
        }
    }
}
