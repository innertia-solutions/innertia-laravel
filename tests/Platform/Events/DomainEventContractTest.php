<?php

require_once __DIR__ . '/helpers/SampleEvents.php';

it('resolves key from the enum', function () {
    $event = new SampleFooCreated('bar');
    expect($event->resolvedKey())->toBe('foo.created');
});

it('appends variant when present', function () {
    $event = new SampleFooUpdatedWithVariant('bar', 'name');
    expect($event->resolvedKey())->toBe('foo.updated.name');
});

it('does not append variant when null', function () {
    $event = new SampleFooCreated('bar');
    expect($event->variant())->toBeNull();
    expect($event->resolvedKey())->toBe('foo.created');
});

it('webhookKey method is gone (BC break)', function () {
    $event = new SampleFooCreated('bar');
    expect(method_exists($event, 'webhookKey'))->toBeFalse();
});

it('resolvedKey is final and not overrideable', function () {
    $reflection = new ReflectionMethod(\Innertia\Platform\Events\DomainEvent::class, 'resolvedKey');
    expect($reflection->isFinal())->toBeTrue();
});
