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
    ) {}

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
