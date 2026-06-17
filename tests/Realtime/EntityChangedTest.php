<?php

use Illuminate\Support\Facades\Event;
use Innertia\Platform\Events\EntityChanged;

it('broadcasts on entity.{table} with event entity.{table}.changed and minimal payload', function () {
    $e = new EntityChanged(table: 'projects', ids: ['a', 'b'], actions: ['updated']);

    expect($e->broadcastAs())->toBe('entity.projects.changed');
    expect($e->channel()->name)->toBe('entity.projects');           // canal público
    expect($e->channels())->toContain('realtime');
    $payload = $e->broadcastWith();
    expect($payload)->toHaveKeys(['table', 'ids', 'actions']);
    expect($payload)->not->toHaveKey('record');                      // nunca el registro
});

it('uses a private channel when marked private', function () {
    $e = new EntityChanged(table: 'evaluations', ids: ['x'], actions: ['created'], private: true);
    expect($e->channel())->toBeInstanceOf(\Illuminate\Broadcasting\PrivateChannel::class);
    // PrivateChannel antepone "private-" al name
    expect($e->channel()->name)->toBe('private-entity.evaluations');
});

it('dispatches as a domain event (assertable via Event::fake)', function () {
    Event::fake([EntityChanged::class]);
    EntityChanged::dispatch(table: 'projects', ids: ['a'], actions: ['updated']);
    Event::assertDispatched(EntityChanged::class, fn ($e) => $e->table === 'projects');
});
