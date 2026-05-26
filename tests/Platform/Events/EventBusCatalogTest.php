<?php

require_once __DIR__ . '/helpers/SampleEvents.php';

use Innertia\Platform\Events\EventBus;

beforeEach(fn () => $this->bus = new EventBus());

it('registers an enum in the catalog', function () {
    $this->bus->registerCatalog(SampleFooEvent::class);

    $catalog = $this->bus->catalog();

    expect($catalog)->toHaveKey('SampleFooEvent');
    expect($catalog['SampleFooEvent']['enum'])->toBe(SampleFooEvent::class);
    expect($catalog['SampleFooEvent']['cases'])->toEqual(['Created', 'Updated']);
});

it('counts listeners per case', function () {
    $this->bus
        ->registerCatalog(SampleFooEvent::class)
        ->listen(SampleFooEvent::Created, fn () => null)
        ->listen(SampleFooEvent::Created, fn () => null)
        ->listen(SampleFooEvent::Updated, fn () => null);

    $catalog = $this->bus->catalog();

    expect($catalog['SampleFooEvent']['listeners'])->toEqual([
        'foo.created' => 2,
        'foo.updated' => 1,
    ]);
});

it('rejects non-DomainEventKey enums', function () {
    expect(fn () => $this->bus->registerCatalog(\stdClass::class))
        ->toThrow(\InvalidArgumentException::class);
});
