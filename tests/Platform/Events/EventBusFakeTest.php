<?php

require_once __DIR__ . '/helpers/SampleEvents.php';

use Innertia\Platform\Events\EventBus;
use Innertia\Platform\Events\EventBusFake;

beforeEach(function () {
    $this->fake = new EventBusFake();
});

it('assertDispatched passes when event was dispatched', function () {
    $this->fake->dispatch(new SampleFooCreated('x'));
    $this->fake->assertDispatched(SampleFooEvent::Created);
});

it('assertDispatched with callback', function () {
    $this->fake->dispatch(new SampleFooCreated('x'));
    $this->fake->assertDispatched(SampleFooEvent::Created, fn ($e) => $e->name === 'x');
});

it('assertDispatched fails when event was not dispatched', function () {
    expect(fn () => $this->fake->assertDispatched(SampleFooEvent::Created))
        ->toThrow(\PHPUnit\Framework\AssertionFailedError::class);
});

it('assertDispatchedTimes counts correctly', function () {
    $this->fake->dispatch(new SampleFooCreated('a'));
    $this->fake->dispatch(new SampleFooCreated('b'));
    $this->fake->assertDispatchedTimes(SampleFooEvent::Created, 2);
});

it('assertNotDispatched passes when event was not dispatched', function () {
    $this->fake->assertNotDispatched(SampleFooEvent::Created);
});

it('assertNothingDispatched passes on empty bus', function () {
    $this->fake->assertNothingDispatched();
});

it('does NOT invoke real listeners', function () {
    $invoked = false;
    $this->fake->listen(SampleFooEvent::Created, function () use (&$invoked) { $invoked = true; });

    $this->fake->dispatch(new SampleFooCreated('x'));

    expect($invoked)->toBeFalse();
});

it('static fake() replaces the singleton in container', function () {
    app()->singleton(EventBus::class);
    EventBusFake::fake();

    expect(app(EventBus::class))->toBeInstanceOf(EventBusFake::class);
});
