<?php

require_once __DIR__ . '/helpers/SampleEvents.php';

it('returns the enum string value as key', function () {
    expect(SampleFooEvent::Created->key())->toBe('foo.created');
    expect(SampleFooEvent::Updated->key())->toBe('foo.updated');
});

it('enforces DomainEventKey contract on enums', function () {
    expect(SampleFooEvent::Created)->toBeInstanceOf(\Innertia\Platform\Events\DomainEventKey::class);
});
