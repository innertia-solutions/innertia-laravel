<?php

require_once __DIR__ . '/helpers/SampleEvents.php';

use Innertia\Platform\Events\EventBus;

beforeEach(function () {
    SampleFooCreatedTrigger::$invocations = [];
    $this->bus = new EventBus();
});

it('registers a trigger via class FQN', function () {
    $this->bus->trigger(SampleFooCreatedTrigger::class);

    $this->bus->dispatch(new SampleFooCreated('x'));

    expect(SampleFooCreatedTrigger::$invocations)->toHaveCount(1);
    expect(SampleFooCreatedTrigger::$invocations[0]->name)->toBe('x');
});

it('rejects classes that do not implement Trigger', function () {
    expect(fn () => $this->bus->trigger(\stdClass::class))
        ->toThrow(\InvalidArgumentException::class);
});
