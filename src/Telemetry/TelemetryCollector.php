<?php

namespace Innertia\Telemetry;

use Innertia\Telemetry\Events\DevtoolsEvent;

class TelemetryCollector
{
    /** @var TelemetryEvent[] */
    private array $batch = [];

    /** Cuando está suspendido, record() ignora eventos (evita capturar las
     *  propias escrituras del TelemetryStore vía DB::listen). */
    private bool $suspended = false;

    private readonly string $traceId;

    public function __construct(
        private readonly string  $appName,
        private readonly string  $sessionId,
        private readonly ?string $tenant,
        private readonly string  $env,
    ) {
        $this->traceId = class_exists(\Hidehalo\Nanoid\Client::class)
            ? (new \Hidehalo\Nanoid\Client())->generateId(21)
            : \Illuminate\Support\Str::uuid()->toString();
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function record(TelemetryEvent $event): void
    {
        if ($this->suspended) {
            return;
        }

        $this->batch[] = $event;
        $this->broadcastToDevtools($event);
    }

    /** Ejecuta $callback con la captura suspendida (para las escrituras del store). */
    public function withoutCapture(callable $callback): mixed
    {
        $previous = $this->suspended;
        $this->suspended = true;

        try {
            return $callback();
        } finally {
            $this->suspended = $previous;
        }
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
