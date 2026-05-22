<?php

use Innertia\Platform\Models\Subscription;

function makeSubscription(array $events): Subscription
{
    $sub = new Subscription();
    $sub->events = $events;
    $sub->channels = ['web'];
    return $sub;
}

it('matches exact event key', function () {
    $sub = makeSubscription(['workflow.transitioned.findings']);
    expect($sub->matchesEvent('workflow.transitioned.findings'))->toBeTrue();
    expect($sub->matchesEvent('workflow.transitioned.closure'))->toBeFalse();
});

it('matches wildcard *', function () {
    $sub = makeSubscription(['*']);
    expect($sub->matchesEvent('workflow.transitioned.findings'))->toBeTrue();
    expect($sub->matchesEvent('project.created'))->toBeTrue();
});

it('matches dot-notation prefix with .*', function () {
    $sub = makeSubscription(['workflow.*']);
    expect($sub->matchesEvent('workflow.transitioned.findings'))->toBeTrue();
    expect($sub->matchesEvent('workflow.started'))->toBeTrue();
    expect($sub->matchesEvent('project.created'))->toBeFalse();
});

it('matches two-level prefix with .*', function () {
    $sub = makeSubscription(['workflow.transitioned.*']);
    expect($sub->matchesEvent('workflow.transitioned.findings'))->toBeTrue();
    expect($sub->matchesEvent('workflow.transitioned.closure'))->toBeTrue();
    expect($sub->matchesEvent('workflow.started'))->toBeFalse();
});

it('matches any of multiple patterns', function () {
    $sub = makeSubscription(['project.created', 'workflow.transitioned.*']);
    expect($sub->matchesEvent('project.created'))->toBeTrue();
    expect($sub->matchesEvent('workflow.transitioned.findings'))->toBeTrue();
    expect($sub->matchesEvent('workflow.started'))->toBeFalse();
});
