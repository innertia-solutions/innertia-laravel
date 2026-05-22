<?php

use Innertia\Workflow\Exceptions\WorkflowRestrictionException;

test('WorkflowRestrictionException stores restrictionType and message', function () {
    $exception = new WorkflowRestrictionException('role', 'You do not have the required role.');

    expect($exception->restrictionType)->toBe('role');
    expect($exception->getMessage())->toBe('You do not have the required role.');
    expect($exception)->toBeInstanceOf(\RuntimeException::class);
});

test('WorkflowRestrictionException with different restriction types', function () {
    $types = ['checklist', 'required_fields', 'role', 'approval', 'min_items', 'document', 'date', 'custom'];

    foreach ($types as $type) {
        $exception = new WorkflowRestrictionException($type, "Restriction failed: {$type}");
        expect($exception->restrictionType)->toBe($type);
    }
});
