<?php

namespace Innertia\Telemetry\Support;

class PayloadSanitizer
{
    /** @param string[] $sensitiveKeys */
    public function __construct(private readonly array $sensitiveKeys) {}

    public function redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            $out[$key] = is_array($value) ? $this->redact($value) : $value;
        }
        return $out;
    }

    private function isSensitive(string $key): bool
    {
        $k = strtolower($key);
        foreach ($this->sensitiveKeys as $needle) {
            if (str_contains($k, strtolower($needle))) {
                return true;
            }
        }
        return false;
    }
}
