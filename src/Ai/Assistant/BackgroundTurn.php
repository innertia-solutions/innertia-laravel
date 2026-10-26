<?php

namespace Innertia\Ai\Assistant;

/**
 * Resultado síncrono de despachar un turno del asistente en segundo plano: la
 * conversación ya creada, el canal realtime donde el job broadcastea los deltas,
 * y el trace_id. El resultado final (mensaje del asistente) lo persiste el job
 * al terminar, vía el callback then() de runInBackground().
 */
final class BackgroundTurn
{
    public function __construct(
        public readonly string $conversationId,
        public readonly string $channel,
        public readonly ?string $traceId = null,
    ) {}
}
