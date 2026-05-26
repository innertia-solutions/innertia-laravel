<?php

require_once __DIR__ . '/helpers/SampleEvents.php';

use Innertia\Platform\Events\EventBus;

beforeEach(fn () => $this->bus = new EventBus());

it('invokes listener registered for a key', function () {
    $captured = [];
    $this->bus->listen(SampleFooEvent::Created, function ($event) use (&$captured) {
        $captured[] = $event->name;
    });

    $this->bus->dispatch(new SampleFooCreated('one'));
    $this->bus->dispatch(new SampleFooCreated('two'));

    expect($captured)->toEqual(['one', 'two']);
});

it('invokes multiple listeners in registration order', function () {
    $order = [];
    $this->bus
        ->listen(SampleFooEvent::Created, function () use (&$order) { $order[] = 'a'; })
        ->listen(SampleFooEvent::Created, function () use (&$order) { $order[] = 'b'; })
        ->listen(SampleFooEvent::Created, function () use (&$order) { $order[] = 'c'; });

    $this->bus->dispatch(new SampleFooCreated('x'));

    expect($order)->toEqual(['a', 'b', 'c']);
});

it('isolates listener exceptions — others still run', function () {
    $reached = [];
    $this->bus
        ->listen(SampleFooEvent::Created, function () { throw new \RuntimeException('boom'); })
        ->listen(SampleFooEvent::Created, function () use (&$reached) { $reached[] = 'second'; });

    $this->bus->dispatch(new SampleFooCreated('x'));

    expect($reached)->toEqual(['second']);
});

it('does not invoke listeners for other keys', function () {
    $count = 0;
    $this->bus->listen(SampleFooEvent::Updated, function () use (&$count) { $count++; });

    $this->bus->dispatch(new SampleFooCreated('x'));

    expect($count)->toBe(0);
});

it('forget removes all listeners for a key', function () {
    $count = 0;
    $this->bus->listen(SampleFooEvent::Created, function () use (&$count) { $count++; });
    $this->bus->forget(SampleFooEvent::Created);

    $this->bus->dispatch(new SampleFooCreated('x'));

    expect($count)->toBe(0);
});

it('when predicate filters invocation', function () {
    $count = 0;
    $this->bus->when(
        SampleFooEvent::Created,
        fn ($event) => $event->name === 'allowed',
        function ($event) use (&$count) { $count++; }
    );

    $this->bus->dispatch(new SampleFooCreated('allowed'));
    $this->bus->dispatch(new SampleFooCreated('blocked'));

    expect($count)->toBe(1);
});

it('listenMany registers all entries', function () {
    $events = [];
    $this->bus->listenMany([
        SampleFooEvent::Created->key() => function ($e) use (&$events) { $events[] = 'created:' . $e->name; },
        SampleFooEvent::Updated->key() => function ($e) use (&$events) { $events[] = 'updated:' . $e->name; },
    ]);

    $this->bus->dispatch(new SampleFooCreated('x'));

    expect($events)->toEqual(['created:x']);
});
