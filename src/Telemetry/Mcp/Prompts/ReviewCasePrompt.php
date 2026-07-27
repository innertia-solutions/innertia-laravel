<?php

namespace Innertia\Telemetry\Mcp\Prompts;

use Innertia\Telemetry\Cases\ReviewPromptBuilder;
use Innertia\Telemetry\Models\TelemetryCase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Description('Arma un prompt de revisión con todo el contexto de un caso de error.')]
class ReviewCasePrompt extends Prompt
{
    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'case_id',
                description: 'ID del caso a revisar',
                required: true,
            ),
        ];
    }

    public function handle(Request $request): Response
    {
        $case = TelemetryCase::find((int) $request->get('case_id'));

        if (! $case) {
            return Response::text('No existe un caso con ese ID.');
        }

        return Response::text((new ReviewPromptBuilder())->forCase($case));
    }
}
