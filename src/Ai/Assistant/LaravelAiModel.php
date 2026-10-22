<?php

namespace Innertia\Ai\Assistant;

use Innertia\Ai\Assistant\Contracts\AssistantModel;

/** Impl de producción: corre un GenericAgent de laravel/ai contra el provider openai-compatible. */
class LaravelAiModel implements AssistantModel
{
    public function respond(
        string $instructions,
        array $history,
        string $userText,
        array $attachments,
        iterable $tools,
        string $model,
    ): AssistantReply {
        $agent = new GenericAgent($instructions, $tools, $history);

        $response = $agent->prompt($userText, $attachments, provider: 'openai-compatible', model: $model);

        return new AssistantReply(
            $response->text,
            $response->usage->promptTokens,
            $response->usage->completionTokens,
        );
    }
}
