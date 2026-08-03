<?php

namespace Innertia\Telemetry;

class TelemetryTags
{
    private array $tags = [];

    public function add(string $key, string|int|float|bool|null $value): void
    {
        $this->tags[$key] = $value;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->tags;
    }
}
