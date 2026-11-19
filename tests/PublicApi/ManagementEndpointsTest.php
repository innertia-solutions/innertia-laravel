<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Innertia\ApiKeys\ApiScopes;
use Innertia\ApiKeys\Models\ApiKey;
use Innertia\Facades\Innertia;
use Innertia\Notifications\NoticeTypes;
use Innertia\PublicApi\Routes;
use Innertia\Saas\Models\Tenant;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\WebhookEvents;

beforeEach(function () {
    bootPublicApiOpenMode($this->app);
    ApiScopes::register('calls:read', 'Consultar llamadas');
    ApiScopes::register('people:pii', 'Ver RUT completo');
    WebhookEvents::register('call.ended', 'Una llamada terminó', 'v1', ['id' => 'c_1']);
    Routes::manage();
});

it('registers the webhook.disabled notice type only when the product mounts the management routes', function () {
    expect(NoticeTypes::get('webhook.disabled'))->not->toBeNull();
});

it('creates, lists, rotates and revokes integration keys (secret shown once)', function () {
    $created = $this->postJson('/integrations/api-keys', ['name' => 'ERP', 'scopes' => ['calls:read'], 'organization_id' => 3])
        ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.organization_id', '3');
    $secret = $created->json('secret');
    $id = $created->json('data.id');

    expect($secret)->toStartWith('pk_live_');
    $list = $this->getJson('/integrations/api-keys')->assertOk()->assertJsonCount(1, 'data');
    expect($list->getContent())->not->toContain($secret)->not->toContain(hash('sha256', $secret));

    $this->getJson('/integrations/api-keys/scopes')->assertJsonPath('data.0.key', 'calls:read')->assertJsonPath('data.0.group', 'calls');

    $rotated = $this->postJson("/integrations/api-keys/{$id}/rotate")->assertOk();
    expect($rotated->json('secret'))->not->toBe($secret)->and($rotated->json('previous.id'))->toBe($id);

    $this->deleteJson("/integrations/api-keys/{$id}")->assertOk()->assertJsonPath('data.status', 'revoked');
    $this->getJson("/integrations/api-keys/{$id}/requests")->assertOk()->assertJsonPath('meta.next_cursor', null);

    $this->postJson('/integrations/api-keys', ['name' => 'X', 'scopes' => ['nope:read']])->assertStatus(422)->assertJsonValidationErrors('scopes');
});

it('isolates keys and webhooks per space', function () {
    $this->postJson('/integrations/api-keys', ['name' => 'ERP', 'scopes' => ['calls:read']])->assertCreated();
    $this->postJson('/integrations/webhooks', ['url' => 'https://h.example.com/x', 'events' => ['call.ended']])->assertCreated();
    $webhookId = Webhook::sole()->id;
    $keyId = ApiKey::sole()->id;

    Tenant::create(['key' => 'machali', 'name' => 'Machalí', 'status' => 'active']);
    Innertia::activate('machali');

    $this->getJson('/integrations/api-keys')->assertJsonCount(0, 'data');
    $this->getJson('/integrations/webhooks')->assertJsonCount(0, 'data');
    $this->getJson("/integrations/webhooks/{$webhookId}")->assertNotFound();
    $this->deleteJson("/integrations/api-keys/{$keyId}")->assertNotFound();
});

it('manages webhooks: create (secret once), update, events catalog, test, logs, resend, rotate, delete', function () {
    Http::fake(['*' => Http::response('ok')]);

    $created = $this->postJson('/integrations/webhooks', ['url' => 'https://h.example.com/x', 'events' => ['call.*'], 'scopes' => ['people:pii'], 'description' => 'CRM'])
        ->assertCreated()->assertJsonPath('data.legacy', false)->assertJsonPath('data.scopes', ['people:pii']);
    $id = $created->json('data.id');
    expect($created->json('secret'))->toStartWith('whsec_');

    $this->getJson('/integrations/webhooks')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.secret');
    $this->getJson('/integrations/webhooks/events')->assertJsonPath('data.0.key', 'call.ended');

    $this->postJson('/integrations/webhooks', ['url' => 'https://10.0.0.1/x', 'events' => ['call.ended']])->assertStatus(422)->assertJsonValidationErrors('url');
    $this->postJson('/integrations/webhooks', ['url' => 'https://h.example.com/x', 'events' => ['nope']])->assertStatus(422)->assertJsonValidationErrors('events');

    $this->patchJson("/integrations/webhooks/{$id}", ['active' => false, 'events' => ['call.ended']])->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.events', ['call.ended']);

    $test = $this->postJson("/integrations/webhooks/{$id}/test")->assertStatus(202)->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.is_test', true);
    $logId = $test->json('data.id');

    $this->getJson("/integrations/webhooks/{$id}/logs")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/integrations/webhooks/{$id}/logs/{$logId}")->assertOk()->assertJsonPath('data.payload.type', 'webhook.test')->assertJsonStructure(['data' => ['request_headers', 'response_body']]);

    $resent = $this->postJson("/integrations/webhooks/{$id}/logs/{$logId}/resend")->assertStatus(202);
    expect($resent->json('data.message_id'))->toBe($test->json('data.message_id'));

    $rotated = $this->postJson("/integrations/webhooks/{$id}/rotate-secret")->assertOk();
    expect($rotated->json('secret'))->not->toBe($created->json('secret'))->and($rotated->json('data.previous_secret_expires_at'))->not->toBeNull();

    // Re-activating a webhook disabled for failures clears its health state.
    Webhook::find($id)->forceFill(['disabled_at' => now(), 'failing_since' => now()->subDay(), 'disabled_reason' => 'x'])->save();
    $this->patchJson("/integrations/webhooks/{$id}", ['active' => true])->assertJsonPath('data.disabled_at', null)->assertJsonPath('data.failing_since', null);

    $this->deleteJson("/integrations/webhooks/{$id}")->assertOk();
    expect(Webhook::count())->toBe(0);
});

it('paginates delivery logs with a cursor', function () {
    Http::fake(['*' => Http::response('ok')]);
    $id = $this->postJson('/integrations/webhooks', ['url' => 'https://h.example.com/x', 'events' => ['call.ended']])->json('data.id');
    foreach (range(1, 3) as $i) {
        $this->postJson("/integrations/webhooks/{$id}/test");
    }

    $page1 = $this->getJson("/integrations/webhooks/{$id}/logs?limit=2")->assertJsonCount(2, 'data');
    $cursor = $page1->json('meta.next_cursor');
    expect($cursor)->toBeString();

    $this->getJson("/integrations/webhooks/{$id}/logs?limit=2&cursor={$cursor}")->assertJsonCount(1, 'data')->assertJsonPath('meta.next_cursor', null);
    $this->getJson("/integrations/webhooks/{$id}/logs?limit=500")->assertStatus(422);
});
