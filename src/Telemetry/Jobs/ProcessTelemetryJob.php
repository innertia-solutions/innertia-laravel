<?php

namespace Innertia\Telemetry\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Innertia\Telemetry\Events\TelemetryBatchReceived;
use Innertia\Telemetry\Models\TelemetryEvent;

class ProcessTelemetryJob implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly array $batch) {}

    public function handle(): void
    {
        $app    = $this->batch['app'] ?? 'unknown';
        $events = $this->batch['events'] ?? [];

        if (empty($events)) return;

        $now = now();

        $rows = array_map(fn (array $e) => [
            'app'         => $app,
            'session_id'  => $this->batch['session_id'] ?? 'unknown',
            'type'        => $e['type'],
            'occurred_at' => $e['occurred_at'] ?? $now,
            'duration_ms' => isset($e['duration_ms']) ? (int) $e['duration_ms'] : null,
            'payload'     => json_encode($e['payload'] ?? []),
            'context'     => json_encode($e['context'] ?? []),
            'created_at'  => $now,
            'updated_at'  => $now,
        ], $events);

        foreach (array_chunk($rows, 100) as $chunk) {
            TelemetryEvent::insert($chunk);
        }

        $summary = array_count_values(array_column($events, 'type'));
        try {
            broadcast(new TelemetryBatchReceived($app, count($events), $summary));
        } catch (\Throwable) {}
    }

    public function queue(): string
    {
        return config('telemetry.queue', 'telemetry');
    }
}
