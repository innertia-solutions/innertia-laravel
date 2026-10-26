<?php

namespace Innertia\Ai\Assistant;

use Illuminate\Broadcasting\Channel;
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

    /**
     * Variante en streaming (SSE nativo de laravel/ai). Persiste el mensaje del
     * usuario ya; el del asistente + el log de uso se persisten cuando el stream
     * termina, vía el callback then(). Devuelve un StreamingTurn con la respuesta
     * SSE + conversation_id + trace_id (para exponerlos por headers en el controller).
     *
     * @param  array<int, string>  $imageDataUrls
     * @param  iterable  $tools  laravel/ai Tool[]
     */
    public function runStream(
        ?Authenticatable $user,
        string $text,
        array $imageDataUrls = [],
        iterable $tools = [],
        ?string $systemPrompt = null,
        ?string $conversationId = null,
    ): StreamingTurn {
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

        $userId = $user?->getAuthIdentifier();
        $agent = new GenericAgent($systemPrompt ?? 'Eres un asistente útil.', $tools, $history);

        // El stream es lazy: no toca la red hasta que se itera (toResponse()).
        $stream = $agent->stream($text, [], provider: 'openai-compatible', model: $model)
            ->withinConversation((string) $conversation->id)
            ->then(function ($final) use ($conversation, $userId, $model) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $final->text ?? '',
                    'created_at' => now(),
                ]);

                Ai::usage()->record(
                    'assistant',
                    null,
                    $model,
                    $final->usage->promptTokens ?? 0,
                    $final->usage->completionTokens ?? 0,
                    $userId,
                );
            });

        return new StreamingTurn($stream, (string) $conversation->id, $this->currentTraceId());
    }

    /**
     * Variante en segundo plano: despacha el turno como un job de cola que corre el
     * stream del agente y broadcastea cada delta al canal realtime público indicado
     * (sobrevive al cierre de la pestaña, no bloquea el request). El mensaje del
     * asistente + el log de uso se persisten cuando el job termina, vía then(); los
     * callbacks opcionales onComplete/onFailed permiten al producto marcar su estado.
     * Requiere un worker de cola corriendo (QUEUE_CONNECTION != sync).
     *
     * @param  array<int, string>  $imageDataUrls
     * @param  iterable  $tools  laravel/ai Tool[]
     * @param  (callable(string $reply): void)|null  $onComplete
     * @param  (callable(string $error): void)|null  $onFailed
     */
    public function runInBackground(
        ?Authenticatable $user,
        string $text,
        string $channel,
        array $imageDataUrls = [],
        iterable $tools = [],
        ?string $systemPrompt = null,
        ?string $conversationId = null,
        ?callable $onComplete = null,
        ?callable $onFailed = null,
        string $queue = 'agents',
    ): BackgroundTurn {
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

        $userId = $user?->getAuthIdentifier();
        $conversationIdStr = (string) $conversation->id;
        $agent = new GenericAgent($systemPrompt ?? 'Eres un asistente útil.', $tools, $history);

        $queued = $agent->broadcastOnQueue($text, [new Channel($channel)], [], provider: 'openai-compatible', model: $model);

        // Cola dedicada para los jobs largos de IA (no bloquean las colas rápidas).
        // onQueue se proxea al PendingDispatch (muta el job); then/catch quedan sobre el QueuedAgentResponse.
        $queued->onQueue($queue);

        $queued
            ->then(function ($final) use ($conversationIdStr, $userId, $model, $onComplete) {
                Message::create([
                    'conversation_id' => $conversationIdStr,
                    'role' => 'assistant',
                    'content' => $final->text ?? '',
                    'created_at' => now(),
                ]);

                Ai::usage()->record(
                    'assistant',
                    null,
                    $model,
                    $final->usage->promptTokens ?? 0,
                    $final->usage->completionTokens ?? 0,
                    $userId,
                );

                if ($onComplete) {
                    $onComplete($final->text ?? '');
                }
            });

        if ($onFailed) {
            $queued->catch(function ($e) use ($onFailed) {
                $onFailed($e instanceof \Throwable ? $e->getMessage() : (string) $e);
            });
        }

        return new BackgroundTurn($conversationIdStr, $channel, $this->currentTraceId());
    }

    /**
     * trace_id del request actual (best-effort, solo si el producto tiene Telemetry).
     */
    private function currentTraceId(): ?string
    {
        if (class_exists(\Innertia\Telemetry\TelemetryCollector::class)
            && app()->bound(\Innertia\Telemetry\TelemetryCollector::class)) {
            return app(\Innertia\Telemetry\TelemetryCollector::class)->traceId();
        }

        return null;
    }
}
