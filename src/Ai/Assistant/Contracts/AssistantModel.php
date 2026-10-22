<?php

namespace Innertia\Ai\Assistant\Contracts;

use Innertia\Ai\Assistant\AssistantReply;

/**
 * Seam del modelo de chat: produce una respuesta a partir de instrucciones,
 * historial, texto del usuario, adjuntos y tools. La impl de producción delega
 * en laravel/ai; en tests se inyecta un fake (sin red).
 *
 * @param array<int, array{role: string, content: string}> $history
 */
interface AssistantModel
{
    public function respond(
        string $instructions,
        array $history,
        string $userText,
        array $attachments,
        iterable $tools,
        string $model,
    ): AssistantReply;
}
