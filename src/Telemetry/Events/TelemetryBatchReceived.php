<?php

namespace Innertia\Telemetry\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class TelemetryBatchReceived implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly string $app,
        public readonly int    $count,
        public readonly array  $summary,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('innertia.olimpo.telemetry');
    }

    public function broadcastAs(): string
    {
        return 'telemetry.batch';
    }

    public function broadcastWith(): array
    {
        return [
            'app'     => $this->app,
            'count'   => $this->count,
            'summary' => $this->summary,
        ];
    }
}
