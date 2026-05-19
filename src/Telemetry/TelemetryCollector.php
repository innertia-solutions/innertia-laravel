<?php

namespace Innertia\Telemetry;

use Innertia\Telemetry\Events\DevtoolsEvent;

class TelemetryCollector
{
    /** @var TelemetryEvent[] */
    private array $batch = [];

    public function __construct(
        private readonly string  $appName,
        private readonly string  $sessionId,
        private readonly ?string $tenant,
        private readonly string  $env,
    ) {}

    public function record(TelemetryEvent $event): void
    {
        $this->batch[] = $event;
        $this->broadcastToDevtools($event);
    }

    public function flush(): array
    {
        $batch       = $this->batch;
        $this->batch = [];
        return $batch;
    }

    /** @return TelemetryEvent[] */
    public function batch(): array
    {
        return $this->batch;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function appName(): string
    {
        return $this->appName;
    }

    public function tenant(): ?string
    {
        return $this->tenant;
    }

    private function broadcastToDevtools(TelemetryEvent $event): void
    {
        try {
            if (function_exists('broadcast') && config('broadcasting.default') !== 'log') {
                broadcast(new DevtoolsEvent($event, $this->sessionId));
            }
        } catch (\Throwable) {
            // Silencioso
        }
    }
}
