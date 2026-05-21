<?php

use Innertia\Devtools\Tinker\TinkerSession;

beforeEach(function () {
    config(['innertia.devtools.tinker.session_ttl' => 1800]);
    // Use array cache driver so no real Redis needed in tests
    config(['cache.default' => 'array']);
    config(['innertia.devtools.tinker.cache_store' => 'array']);
});

it('creates a session with a uuid id', function () {
    $session = TinkerSession::create();

    expect($session->id())->toMatch('/^[0-9a-f-]{36}$/');
});

it('can be found by id after creation', function () {
    $session = TinkerSession::create();
    $found   = TinkerSession::find($session->id());

    expect($found)->not->toBeNull()
        ->and($found->id())->toBe($session->id());
});

it('returns null when session does not exist', function () {
    expect(TinkerSession::find('non-existent-id'))->toBeNull();
});

it('persists and retrieves variables', function () {
    $session = TinkerSession::create();
    $session->save(['foo' => 'bar', 'count' => 42]);

    $found = TinkerSession::find($session->id());
    expect($found->variables())->toBe(['foo' => 'bar', 'count' => 42]);
});

it('exposes the correct broadcast channel name', function () {
    $session = TinkerSession::create();

    expect($session->channel())->toBe('private-innertia.tinker.' . $session->id());
});

it('can be destroyed', function () {
    $session = TinkerSession::create();
    $id      = $session->id();

    $session->destroy();

    expect(TinkerSession::find($id))->toBeNull();
});
