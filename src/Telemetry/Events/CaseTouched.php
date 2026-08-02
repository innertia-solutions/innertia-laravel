<?php

namespace Innertia\Telemetry\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Innertia\Telemetry\Models\TelemetryCase;

/**
 * Señal realtime para el panel de Observabilidad: un caso se creó o reincidió.
 * Canal público 'observability', evento 'case.touched'.
 */
class CaseTouched implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(
        public readonly TelemetryCase $case,
        public readonly bool $isNew,
        public readonly bool $isReopened,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('observability');
    }

    public function broadcastAs(): string
    {
        return 'case.touched';
    }

    public function broadcastWith(): array
    {
        return [
            'id'              => $this->case->getKey(),
            'title'           => $this->case->title,
            'exception_class' => $this->case->exception_class,
            'times_seen'      => (int) $this->case->times_seen,
            'status'          => $this->case->status,
            'last_seen'       => optional($this->case->last_seen)->toIso8601String(),
            'is_new'          => $this->isNew,
            'is_reopened'     => $this->isReopened,
        ];
    }
}
