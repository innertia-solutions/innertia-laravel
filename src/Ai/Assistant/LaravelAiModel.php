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
            self::textOrStepLimitNotice($response),
            $response->usage->promptTokens,
            $response->usage->completionTokens,
        );
    }

    /**
     * Si el loop se cortó por el límite de pasos (último paso pidiendo tools), el
     * texto viene vacío: en vez de una respuesta en blanco, avisar qué se alcanzó
     * a hacer para que el usuario pueda pedir que continúe.
     */
    public static function textOrStepLimitNotice(object $response): string
    {
        $text = trim((string) ($response->text ?? ''));
        if ($text !== '') {
            return $text;
        }

        $calls = collect($response->toolCalls ?? [])
            ->map(fn ($c) => is_array($c) ? ($c['name'] ?? null) : ($c->name ?? null))
            ->filter()
            ->countBy()
            ->map(fn ($n, $name) => "{$name} ×{$n}")
            ->values()
            ->implode(', ');

        return 'Se alcanzó el límite de pasos antes de terminar'
            .($calls !== '' ? " (herramientas usadas: {$calls})" : '')
            .'. Pídeme que continúe para completar lo que falta.';
    }
}
