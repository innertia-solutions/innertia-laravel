<?php

use Innertia\Workflow\Enums\WorkflowEvent;
use Innertia\Workflow\Enums\WorkflowStatus;
use Innertia\Workflow\Enums\WorkflowStepType;

test('WorkflowStepType has correct values', function () {
    expect(WorkflowStepType::Start->value)->toBe('start');
    expect(WorkflowStepType::InProgress->value)->toBe('in_progress');
    expect(WorkflowStepType::PauseInternal->value)->toBe('pause_internal');
    expect(WorkflowStepType::PauseExternal->value)->toBe('pause_external');
    expect(WorkflowStepType::Finished->value)->toBe('finished');
    expect(WorkflowStepType::Cancelled->value)->toBe('cancelled');
});

test('WorkflowStepType isTerminal', function () {
    expect(WorkflowStepType::Finished->isTerminal())->toBeTrue();
    expect(WorkflowStepType::Cancelled->isTerminal())->toBeTrue();
    expect(WorkflowStepType::Start->isTerminal())->toBeFalse();
    expect(WorkflowStepType::InProgress->isTerminal())->toBeFalse();
    expect(WorkflowStepType::PauseInternal->isTerminal())->toBeFalse();
    expect(WorkflowStepType::PauseExternal->isTerminal())->toBeFalse();
});

test('WorkflowStatus has correct values', function () {
    expect(WorkflowStatus::Active->value)->toBe('active');
    expect(WorkflowStatus::Finished->value)->toBe('finished');
    expect(WorkflowStatus::Cancelled->value)->toBe('cancelled');
});

test('WorkflowEvent has correct keys', function () {
    expect(WorkflowEvent::Started->value)->toBe('workflow.started');
    expect(WorkflowEvent::Transitioned->value)->toBe('workflow.transitioned');
    expect(WorkflowEvent::TransitionBlocked->value)->toBe('workflow.transition_blocked');
    expect(WorkflowEvent::Finished->value)->toBe('workflow.finished');
    expect(WorkflowEvent::Cancelled->value)->toBe('workflow.cancelled');
});

test('WorkflowEvent forStep returns granular key', function () {
    expect(WorkflowEvent::Transitioned->forStep('findings'))->toBe('workflow.transitioned.findings');
    expect(WorkflowEvent::Transitioned->forStep('planning'))->toBe('workflow.transitioned.planning');
    expect(WorkflowEvent::Started->forStep('start'))->toBe('workflow.started.start');
});
