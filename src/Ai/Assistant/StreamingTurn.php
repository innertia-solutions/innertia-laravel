<?php

namespace Innertia\Ai\Assistant;

use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Resultado de un turno en streaming: la respuesta SSE de laravel/ai
 * (StreamableAgentResponse::toResponse() = text/event-stream) + el id de la
 * conversación y el trace_id, para que el controller los exponga por headers.
 */
final class StreamingTurn
{
    public function __construct(
        public readonly StreamableAgentResponse $response,
        public readonly string $conversationId,
        public readonly ?string $traceId = null,
    ) {}
}
