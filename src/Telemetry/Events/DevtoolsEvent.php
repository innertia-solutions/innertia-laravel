<?php

namespace Innertia\Telemetry\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Innertia\Telemetry\TelemetryEvent;

class DevtoolsEvent implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly TelemetryEvent $event,
        public readonly string         $sessionId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("innertia.devtools.{$this->sessionId}");
    }

    public function broadcastAs(): string
    {
        return 'devtools.' . $this->event->type;
    }

    public function broadcastWith(): array
    {
        return $this->event->toArray();
    }
}
