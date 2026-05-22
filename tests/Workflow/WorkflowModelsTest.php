<?php

use Innertia\Workflow\Models\WorkflowDefinition;
use Innertia\Workflow\Models\WorkflowInstance;
use Innertia\Workflow\Models\WorkflowTransitionLog;
use Innertia\Workflow\Traits\HasWorkflow;

test('WorkflowDefinition findStep returns null for missing key', function () {
    $definition = new WorkflowDefinition();
    $definition->config = [
        'steps' => [
            ['key' => 'planning', 'label' => 'Planificación', 'type' => 'start'],
            ['key' => 'execution', 'label' => 'Ejecución', 'type' => 'in_progress'],
        ],
        'transitions' => [],
    ];

    expect($definition->findStep('planning'))->toBe(['key' => 'planning', 'label' => 'Planificación', 'type' => 'start']);
    expect($definition->findStep('missing'))->toBeNull();
});

test('WorkflowDefinition findTransition finds correct transition', function () {
    $definition = new WorkflowDefinition();
    $definition->config = [
        'steps' => [],
        'transitions' => [
            ['from' => 'planning', 'to' => 'execution', 'restrictions' => []],
            ['from' => 'execution', 'to' => 'closure', 'restrictions' => []],
        ],
    ];

    $t = $definition->findTransition('planning', 'execution');
    expect($t)->not->toBeNull();
    expect($t['to'])->toBe('execution');

    expect($definition->findTransition('planning', 'closure'))->toBeNull();
});

test('WorkflowDefinition transitionsFrom returns correct transitions', function () {
    $definition = new WorkflowDefinition();
    $definition->config = [
        'steps' => [],
        'transitions' => [
            ['from' => 'planning', 'to' => 'execution', 'restrictions' => []],
            ['from' => 'planning', 'to' => 'cancelled', 'restrictions' => []],
            ['from' => 'execution', 'to' => 'closure', 'restrictions' => []],
        ],
    ];

    $transitions = $definition->transitionsFrom('planning');
    expect($transitions)->toHaveCount(2);
    expect(collect($transitions)->pluck('to')->toArray())->toContain('execution');
    expect(collect($transitions)->pluck('to')->toArray())->toContain('cancelled');
});

test('HasWorkflow trait adds workflowInstance method', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model {
        use HasWorkflow;
    };

    expect(method_exists($model, 'workflowInstance'))->toBeTrue();
});
