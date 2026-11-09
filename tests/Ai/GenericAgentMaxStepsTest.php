<?php

use Innertia\Ai\Assistant\GenericAgent;
use Laravel\Ai\Gateway\TextGenerationOptions;

it('sin configuración deja el presupuesto de pasos por defecto de laravel/ai', function () {
    config()->set('innertia.ai.assistant.max_steps', null);

    expect(TextGenerationOptions::forAgent(new GenericAgent('x'))->maxSteps)->toBeNull();
});

it('toma el máximo de pasos desde innertia.ai.assistant.max_steps', function () {
    config()->set('innertia.ai.assistant.max_steps', 30);

    expect(TextGenerationOptions::forAgent(new GenericAgent('x'))->maxSteps)->toBe(30);
});

it('un valor explícito en el constructor gana sobre la configuración', function () {
    config()->set('innertia.ai.assistant.max_steps', 30);

    expect(TextGenerationOptions::forAgent(new GenericAgent('x', [], [], 12))->maxSteps)->toBe(12);
});
