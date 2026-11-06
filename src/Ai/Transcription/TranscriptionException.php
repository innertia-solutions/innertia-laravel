<?php

namespace Innertia\Ai\Transcription;

/**
 * Fallo de un proveedor de transcripción. `status` = HTTP (0 = red), `type` = tipo de error del proveedor
 * (p.ej. `insufficient_balance_error`, `too_long`). `transient()`: red caída, 429 o 5xx (vale reintentar).
 */
class TranscriptionException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $type = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function transient(): bool
    {
        return $this->status === 0 && $this->type === null
            || $this->status === 429
            || in_array($this->status, [500, 502, 503, 504], true);
    }
}
