<?php

namespace Innertia\Ai\Assistant;

/** Resultado de un turno del asistente, agnóstico del proveedor. */
class AssistantReply
{
    public function __construct(
        public string $text,
        public int $promptTokens = 0,
        public int $completionTokens = 0,
    ) {}
}
