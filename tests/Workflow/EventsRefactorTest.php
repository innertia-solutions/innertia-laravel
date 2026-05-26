<?php

use Innertia\Workflow\Enums\WorkflowEvent;

it('WorkflowEvent enum implements DomainEventKey', function () {
    expect(WorkflowEvent::Started)->toBeInstanceOf(\Innertia\Platform\Events\DomainEventKey::class);
});

it('WorkflowEvent::key returns the value', function () {
    expect(WorkflowEvent::Started->key())->toBe('workflow.started');
    expect(WorkflowEvent::Transitioned->key())->toBe('workflow.transitioned');
    expect(WorkflowEvent::TransitionBlocked->key())->toBe('workflow.transition_blocked');
    expect(WorkflowEvent::Finished->key())->toBe('workflow.finished');
    expect(WorkflowEvent::Cancelled->key())->toBe('workflow.cancelled');
});

it('WorkflowTransitioned resolvedKey includes the to_step variant', function () {
    $instance = \Mockery::mock(\Innertia\Workflow\Models\WorkflowInstance::class);
    $user     = \Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);

    $event = new \Innertia\Workflow\Events\WorkflowTransitioned(
        $instance, 'from', 'From', 'findings', 'Findings', $user
    );

    expect($event->key())->toBe(WorkflowEvent::Transitioned);
    expect($event->variant())->toBe('findings');
    expect($event->resolvedKey())->toBe('workflow.transitioned.findings');
});

it('WorkflowStarted has no variant', function () {
    $instance = \Mockery::mock(\Innertia\Workflow\Models\WorkflowInstance::class);
    $user     = \Mockery::mock(\Illuminate\Contracts\Auth\Authenticatable::class);

    $event = new \Innertia\Workflow\Events\WorkflowStarted($instance, $user);

    expect($event->variant())->toBeNull();
    expect($event->resolvedKey())->toBe('workflow.started');
});
