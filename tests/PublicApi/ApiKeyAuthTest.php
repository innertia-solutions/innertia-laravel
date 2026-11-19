<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Innertia\ApiKeys\ApiScopes;
use Innertia\ApiKeys\IntegrationKeys;
use Innertia\ApiKeys\Models\ApiKey;
use Innertia\ApiKeys\UseCases\CreateIntegrationKey;
use Innertia\ApiKeys\UseCases\RevokeIntegrationKey;
use Innertia\ApiKeys\UseCases\RotateIntegrationKey;
use Innertia\Facades\ActivityLogger;
use Innertia\Facades\Innertia;
use Innertia\PublicApi\ActsAsIntegration;
use Innertia\PublicApi\Http\ApiException;
use Innertia\PublicApi\Models\ApiRequestLog;
use Innertia\Saas\Models\Tenant;

beforeEach(function () {
    bootPublicApiOpenMode($this->app);
    ApiScopes::register('calls:read', 'Consultar llamadas');
    ApiScopes::register('calls:write', 'Crear llamadas');

    Route::prefix('api/v1')->middleware('api.key')->group(function () {
        Route::get('calls', fn () => response()->json([
            'tenant' => Innertia::tenant()?->key,
            'org'    => Innertia::organization()?->current(),
            'actor'  => ActsAsIntegration::label(),
        ]))->apiDoc(['summary' => 'Lista llamadas', 'scope' => 'calls:read']);

        Route::post('calls', function (Request $request) {
            $request->validate(['number' => 'required|string']);
            ActivityLogger::log('calls.created');

            return response()->json(['id' => uniqid('c_'), 'number' => $request->input('number')], 201);
        })->middleware('api.key:calls:write');

        Route::get('boom', fn () => throw new ApiException('extension_unavailable', 'El anexo no tiene agente.', 422))
            ->apiDoc(['summary' => 'x', 'scope' => 'calls:read']);
        Route::get('missing', fn () => abort(404))->apiDoc(['summary' => 'x', 'scope' => 'calls:read']);
    });
});

function integrationKey(array $scopes = ['calls:read'], array $extra = []): array
{
    return (new CreateIntegrationKey('ERP', $scopes, $extra['organization_id'] ?? null, $extra['expires_at'] ?? null))->execute();
}

it('stores only a SHA-256 hash with an indexed readable prefix', function () {
    ['key' => $key, 'secret' => $secret] = integrationKey();

    expect($secret)->toMatch('/^pk_live_[A-Za-z0-9]{40}$/')
        ->and($key->prefix)->toBe(substr($secret, 0, 16))
        ->and(DB::table('api_keys')->where('id', $key->id)->value('key'))->toBe(hash('sha256', $secret))
        ->and(json_encode($key->toApiArray()))->not->toContain($secret)
        ->and(IntegrationKeys::find($secret)?->id)->toBe($key->id)
        ->and($key->tenant_id)->toBe((string) Tenant::findByKey('rancagua')->id);
});

it('401 invalid_api_key without, with a wrong, a revoked or an expired key', function () {
    ['key' => $key, 'secret' => $secret] = integrationKey();
    ['secret' => $expiring] = integrationKey(extra: ['expires_at' => now()->addHour()]);

    $this->getJson('/api/v1/calls')->assertStatus(401)->assertJsonPath('error.code', 'invalid_api_key');
    $this->getJson('/api/v1/calls', ['Authorization' => 'Bearer pk_live_' . str_repeat('x', 40)])->assertStatus(401);

    $this->travel(2)->hours();
    $this->getJson('/api/v1/calls', ['Authorization' => "Bearer {$expiring}"])->assertStatus(401);

    (new RevokeIntegrationKey($key))->execute();
    $this->getJson('/api/v1/calls', ['X-Api-Key' => $secret])->assertStatus(401)->assertJsonPath('error.message', 'La clave de API es inválida, fue revocada o venció.');
});

it('activates the key tenant and sede, acts as the integration and restores the context', function () {
    Tenant::create(['key' => 'machali', 'name' => 'Machalí', 'status' => 'active']);
    ['secret' => $secret] = integrationKey(extra: ['organization_id' => '5']);
    Innertia::activate('machali');

    $this->getJson('/api/v1/calls', ['Authorization' => "Bearer {$secret}"])
        ->assertOk()
        ->assertJson(['tenant' => 'rancagua', 'org' => 5, 'actor' => 'Integración: ERP'])
        ->assertHeader('X-RateLimit-Limit', '120');

    expect(Innertia::tenant()->key)->toBe('machali')->and(ActsAsIntegration::current())->toBeNull();
});

it('403 insufficient_scope with the required scope (descriptor or middleware parameter)', function () {
    ['secret' => $secret] = integrationKey(['calls:read']);

    $this->postJson('/api/v1/calls', ['number' => '+56'], ['Authorization' => "Bearer {$secret}"])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope')
        ->assertJsonPath('error.details.required', ['calls:write']);
});

it('normalizes validation, not found and custom errors to the envelope', function () {
    ['secret' => $secret] = integrationKey(['calls:read', 'calls:write']);
    $h = ['Authorization' => "Bearer {$secret}"];

    $this->postJson('/api/v1/calls', [], $h)->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['number']]]);
    $this->getJson('/api/v1/missing', $h)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    $this->getJson('/api/v1/boom', $h)->assertStatus(422)->assertJsonPath('error.code', 'extension_unavailable');
});

it('rate limits per key with X-RateLimit-* and Retry-After', function () {
    config()->set('innertia.public_api.rate_limit.per_key', 2);
    ['secret' => $secret] = integrationKey();
    $h = ['Authorization' => "Bearer {$secret}"];

    $this->getJson('/api/v1/calls', $h)->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
    $this->getJson('/api/v1/calls', $h)->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
    $this->getJson('/api/v1/calls', $h)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
});

it('rate limits globally per space', function () {
    config()->set('innertia.public_api.rate_limit.per_space', 1);
    ['secret' => $a] = integrationKey();
    ['secret' => $b] = integrationKey();

    $this->getJson('/api/v1/calls', ['Authorization' => "Bearer {$a}"])->assertOk();
    $this->getJson('/api/v1/calls', ['Authorization' => "Bearer {$b}"])->assertStatus(429);
});

it('replays the same Idempotency-Key + body and rejects a different body with 409', function () {
    ['secret' => $secret] = integrationKey(['calls:write']);
    $h = ['Authorization' => "Bearer {$secret}", 'Idempotency-Key' => 'abc-1'];

    $first = $this->postJson('/api/v1/calls', ['number' => '+561'], $h)->assertStatus(201);
    $again = $this->postJson('/api/v1/calls', ['number' => '+561'], $h)->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');

    expect($again->json('id'))->toBe($first->json('id'))
        ->and(DB::table('activity_logs')->count())->toBe(1);

    $this->postJson('/api/v1/calls', ['number' => '+562'], $h)->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');

    $this->travel(25)->hours();
    $this->postJson('/api/v1/calls', ['number' => '+562'], $h)->assertStatus(201);
});

it('logs requests with the key prefix only and records the integration as actor', function () {
    ['key' => $key, 'secret' => $secret] = integrationKey(['calls:write']);

    $this->postJson('/api/v1/calls', ['number' => '+56'], ['Authorization' => "Bearer {$secret}"])->assertStatus(201);

    $log = ApiRequestLog::sole();
    expect($log->only(['method', 'path', 'status', 'key_prefix']))->toBe(['method' => 'POST', 'path' => '/api/v1/calls', 'status' => 201, 'key_prefix' => $key->prefix])
        ->and(json_encode(ApiRequestLog::first()->toArray()))->not->toContain($secret);

    $activity = DB::table('activity_logs')->first();
    expect(json_decode($activity->metadata, true)['actor'])->toMatchArray(['type' => 'integration', 'name' => 'ERP', 'label' => 'Integración: ERP'])
        ->and($key->fresh()->last_used_at)->not->toBeNull();
});

it('rotation keeps the old key valid for 24 h, then only the new one works', function () {
    ['key' => $key, 'secret' => $old] = integrationKey();
    ['secret' => $new, 'previous' => $previous] = (new RotateIntegrationKey($key))->execute();

    expect($previous->rotated_to_id)->not->toBeNull()->and($previous->status())->toBe('expiring');
    $this->getJson('/api/v1/calls', ['Authorization' => "Bearer {$old}"])->assertOk();
    $this->getJson('/api/v1/calls', ['Authorization' => "Bearer {$new}"])->assertOk();

    $this->travel(25)->hours();
    $this->getJson('/api/v1/calls', ['Authorization' => "Bearer {$old}"])->assertStatus(401);
    $this->getJson('/api/v1/calls', ['Authorization' => "Bearer {$new}"])->assertOk();
});

it('rejects unknown scopes on create and keeps the legacy apikey middleware untouched', function () {
    expect(fn () => integrationKey(['admin:everything']))->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(ApiKey::findByRawKey(integrationKey()['secret']))->toBeNull(); // legacy lookup ignores integration keys
});
