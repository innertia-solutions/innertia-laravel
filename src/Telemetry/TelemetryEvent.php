<?php

namespace Innertia\Telemetry;

final class TelemetryEvent
{
    public readonly \DateTimeImmutable $occurredAt;

    public function __construct(
        public readonly string $type,
        public readonly array  $payload,
        public readonly array  $context,
        public readonly ?float $durationMs = null,
        ?\DateTimeImmutable    $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'type'        => $this->type,
            'payload'     => $this->payload,
            'context'     => $this->context,
            'duration_ms' => $this->durationMs,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
