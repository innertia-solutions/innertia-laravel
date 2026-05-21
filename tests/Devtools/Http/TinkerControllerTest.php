<?php

use Illuminate\Support\Facades\Route;
use Innertia\Devtools\Http\Controllers\TinkerController;
use Innertia\Devtools\Http\Middleware\DevtoolsGuard;
use Innertia\Olimpo\Http\Middleware\OlimpoAuth;

beforeEach(function () {
    config(['innertia.devtools.enabled'            => true]);
    config(['innertia.devtools.tinker.enabled'     => true]);
    config(['innertia.devtools.tinker.session_ttl' => 1800]);
    config(['innertia.devtools.tinker.cache_store' => 'array']);
    config(['olimpo.key'                           => 'test-key']);
    config(['cache.default'                        => 'array']);
    config(['broadcasting.default'                 => 'log']); // no real Soketi in tests

    app('router')->aliasMiddleware('olimpo.auth', OlimpoAuth::class);
    app('router')->aliasMiddleware('devtools.guard', DevtoolsGuard::class);

    Route::prefix('innertia/devtools')
        ->middleware(['olimpo.auth', 'devtools.guard'])
        ->group(function () {
            Route::post('tinker/sessions', [TinkerController::class, 'create']);
            Route::post('tinker/sessions/{id}/eval', [TinkerController::class, 'eval']);
            Route::delete('tinker/sessions/{id}', [TinkerController::class, 'destroy']);
        });
});

it('creates a tinker session', function () {
    $response = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions');

    $response->assertCreated()
        ->assertJsonStructure(['session_id', 'channel', 'expires_in']);

    expect($response->json('channel'))->toStartWith('private-innertia.tinker.');
});

it('returns 403 when tinker is disabled', function () {
    config(['innertia.devtools.tinker.enabled' => false]);

    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions')
        ->assertStatus(403);
});

it('evaluates code and returns output', function () {
    $session = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions')
        ->json();

    $response = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson("innertia/devtools/tinker/sessions/{$session['session_id']}/eval", [
            'code' => 'echo "ping";',
        ]);

    $response->assertOk()
        ->assertJsonPath('output', 'ping')
        ->assertJsonPath('error', null);
});

it('rejects blocked functions', function () {
    $session = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions')
        ->json();

    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson("innertia/devtools/tinker/sessions/{$session['session_id']}/eval", [
            'code' => 'exec("ls")',
        ])
        ->assertStatus(422);
});

it('returns 404 for unknown session', function () {
    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions/nonexistent/eval', [
            'code' => 'echo 1;',
        ])
        ->assertNotFound();
});

it('destroys a session', function () {
    $session = $this->withHeader('X-Olimpo-Key', 'test-key')
        ->postJson('innertia/devtools/tinker/sessions')
        ->json();

    $this->withHeader('X-Olimpo-Key', 'test-key')
        ->deleteJson("innertia/devtools/tinker/sessions/{$session['session_id']}")
        ->assertOk()
        ->assertJsonPath('ok', true);
});
