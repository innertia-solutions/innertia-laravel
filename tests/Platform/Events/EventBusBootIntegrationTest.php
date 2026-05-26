<?php

require_once __DIR__ . '/helpers/SampleEvents.php';

use Innertia\Facades\Events;
use Innertia\Platform\Events\EventBus;

it('binds EventBus as singleton', function () {
    $a = app(EventBus::class);
    $b = app(EventBus::class);
    expect($a)->toBe($b);
});

it('routes DomainEvents emitted via Laravel event() through the bus', function () {
    $captured = null;
    Events::listen(SampleFooEvent::Created, function ($event) use (&$captured) {
        $captured = $event->name;
    });

    event(new SampleFooCreated('integration'));

    expect($captured)->toBe('integration');
});

it('Innertia facade events() returns the same singleton', function () {
    expect(\Innertia\Facades\Innertia::events())->toBe(app(EventBus::class));
});
