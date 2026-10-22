<?php

namespace Innertia\Ai\Assistant;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Innertia\Ai\Assistant\Contracts\AssistantModel;
use Innertia\Ai\Facades\Ai;
use Innertia\Ai\Models\Conversation;
use Innertia\Ai\Models\Message;
use Innertia\Ai\Services\VisionOcr;

/**
 * Orquesta un turno del asistente: visión (OCR de imágenes) → resolución de modelo
 * → historial → persistencia de mensajes → invocación del modelo → log de uso.
 * Genérico: el producto aporta sus tools y su system prompt.
 */
class AssistantRunner
{
    public function __construct(
        private ?AssistantModel $model = null,
        private ?VisionOcr $vision = null,
    ) {
        $this->model ??= new LaravelAiModel;
        $this->vision ??= new VisionOcr;
    }

    /**
     * @param  array<int, string>  $imageDataUrls
     * @param  iterable  $tools  laravel/ai Tool[]
     * @return array{conversation_id: string, reply: string, usage: array{prompt_tokens: int, completion_tokens: int}}
     */
    public function run(
        ?Authenticatable $user,
        string $text,
        array $imageDataUrls = [],
        iterable $tools = [],
        ?string $systemPrompt = null,
        ?string $conversationId = null,
    ): array {
        if ($imageDataUrls !== []) {
            $ocr = $this->vision->extract($imageDataUrls);
            if ($ocr !== '') {
                $text = trim($text."\n\n[Texto extraído de imágenes]\n".$ocr);
            }
        }

        $model = Ai::configureFor('assistant') ?? Ai::configureFor('chat') ?? '';

        $conversation = $conversationId
            ? Conversation::findOrFail($conversationId)
            : Conversation::create([
                'user_id' => $user?->getAuthIdentifier(),
                'title' => Str::limit($text, 60),
            ]);

        // History = prior turns (load BEFORE persisting the current user message).
        $history = $conversation->messages()->get()
            ->map(fn (Message $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $text,
            'attachments' => $imageDataUrls ?: null,
            'created_at' => now(),
        ]);

        $reply = $this->model->respond(
            $systemPrompt ?? 'Eres un asistente útil.',
            $history,
            $text,
            [],
            $tools,
            $model,
        );

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply->text,
            'created_at' => now(),
        ]);

        Ai::usage()->record('assistant', null, $model, $reply->promptTokens, $reply->completionTokens, $user?->getAuthIdentifier());

        return [
            'conversation_id' => $conversation->id,
            'reply' => $reply->text,
            'usage' => [
                'prompt_tokens' => $reply->promptTokens,
                'completion_tokens' => $reply->completionTokens,
            ],
        ];
    }
}
