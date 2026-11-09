<?php

namespace Innertia\Ai\Assistant;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Agente genérico de laravel/ai: las instrucciones, tools e historial se inyectan
 * por constructor (el producto aporta sus tools). La invocación (prompt()) la da
 * el trait Promptable.
 */
class GenericAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** @param array<int, array{role: string, content: string}> $history */
    public function __construct(
        private string $agentInstructions,
        private iterable $agentTools = [],
        private array $history = [],
        private ?int $agentMaxSteps = null,
    ) {}

    /**
     * Presupuesto de pasos (llamadas a tools) del loop de laravel/ai. null = su
     * default (1.5 × cantidad de tools), que se queda corto para tareas largas
     * como copiar y clasificar varias preguntas. Configurable por producto en
     * innertia.ai.assistant.max_steps.
     */
    public function maxSteps(): ?int
    {
        $steps = $this->agentMaxSteps ?? config('innertia.ai.assistant.max_steps');

        return $steps !== null ? (int) $steps : null;
    }

    public function instructions(): Stringable|string
    {
        return $this->agentInstructions;
    }

    public function tools(): iterable
    {
        return $this->agentTools;
    }

    public function messages(): iterable
    {
        $messages = [];
        foreach ($this->history as $turn) {
            $messages[] = ($turn['role'] ?? 'user') === 'assistant'
                ? new AssistantMessage($turn['content'] ?? '')
                : new UserMessage($turn['content'] ?? '');
        }

        return $messages;
    }
}
