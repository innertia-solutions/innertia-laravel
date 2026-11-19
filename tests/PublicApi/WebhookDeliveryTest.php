<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Innertia\ApiKeys\ApiScopes;
use Innertia\Notifications\UseCases\DeliverNotice;
use Innertia\Webhooks\Jobs\DispatchWebhookJob;
use Innertia\Webhooks\Models\Webhook;
use Innertia\Webhooks\Models\WebhookLog;
use Innertia\Webhooks\Signing\StandardWebhookSigner;
use Innertia\Webhooks\UseCases\CreateWebhook;
use Innertia\Webhooks\UseCases\RotateWebhookSecret;
use Innertia\Webhooks\WebhookDeliverer;
use Innertia\Webhooks\WebhookEvents;

uses(RefreshDatabase::class);

beforeEach(function () {
    publicApiReset();
    WebhookEvents::register('call.ended', 'Una llamada terminó', 'v1', ['id' => 'c_1']);
    WebhookEvents::register('call.ringing', 'Llamada entrante', 'v1', ['id' => 'c_1']);
    WebhookEvents::register('person.created', 'Persona creada', 'v1', ['id' => 'p_1'], scope: 'people:pii');
    ApiScopes::register('people:pii', 'Ver RUT completo');
});

function makeWebhook(array $events = ['call.*'], array $extra = []): array
{
    return (new CreateWebhook(
        url: $extra['url'] ?? 'https://hooks.example.com/in',
        events: $events,
        organizationId: $extra['organization_id'] ?? null,
        scopes: $extra['scopes'] ?? [],
    ))->execute();
}

it('delivers v2 envelopes with Standard Webhooks + legacy signatures', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
    ['webhook' => $webhook, 'secret' => $secret] = makeWebhook();

    TestCallEvent::dispatch('c_42');

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request) use ($secret) {
        $body = $request->body();
        $id = $request->header('webhook-id')[0];
        $ts = (int) $request->header('webhook-timestamp')[0];

        expect(json_decode($body, true))->toMatchArray(['type' => 'call.ended', 'data' => ['id' => 'c_42', 'rut' => '12.***.***-9', 'site' => null]])
            ->and($id)->toStartWith('msg_')
            ->and(StandardWebhookSigner::verify($secret, $id, $ts, $body, $request->header('webhook-signature')[0]))->toBeTrue()
            ->and($request->header('X-Innertia-Signature')[0])->toBe('sha256=' . hash_hmac('sha256', $body, $secret))
            ->and($request->header('X-Innertia-Event')[0])->toBe('call.ended');

        return true;
    });

    $log = WebhookLog::sole();
    expect($log->status)->toBe('delivered')->and($log->response_status)->toBe(200)->and($log->duration_ms)->toBeInt()
        ->and($webhook->fresh()->last_success_at)->not->toBeNull();
});

it('keeps the legacy body for subscriptions created before v2', function () {
    Http::fake(['*' => Http::response('', 204)]);
    Webhook::create(['url' => 'https://legacy.example.com/h', 'events' => ['*'], 'secret' => 'plain-secret', 'active' => true]);

    TestCallEvent::dispatch('c_7');

    Http::assertSent(function (HttpRequest $request) {
        $data = json_decode($request->body(), true);

        return $data['_event'] === 'call.ended' && $data['callId'] === 'c_7'
            && $request->header('X-Innertia-Signature')[0] === 'sha256=' . hash_hmac('sha256', $request->body(), 'plain-secret')
            && $request->hasHeader('webhook-signature');
    });
});

it('matches wildcards, filters by sede and exposes sensitive fields only with the scope', function () {
    Http::fake(['*' => Http::response('ok')]);
    makeWebhook(['call.ringing'], ['url' => 'https://a.example.com/h']);                       // other event
    makeWebhook(['*'], ['url' => 'https://b.example.com/h', 'scopes' => ['people:pii']]);       // all, with PII
    makeWebhook(['call.*'], ['url' => 'https://c.example.com/h', 'organization_id' => '7']);  // only sede 7

    TestCallEvent::dispatch('c_1', '3');

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'b.example.com') && json_decode($r->body(), true)['data']['rut'] === '12.345.678-9');

    TestCallEvent::dispatch('c_2', '7');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'c.example.com') && json_decode($r->body(), true)['data']['rut'] === '12.***.***-9');
});

it('never follows redirects and truncates the response to 64 KB', function () {
    Http::fake([
        'redirect.example.com/*' => Http::response('', 302, ['Location' => 'https://169.254.169.254/']),
        'big.example.com/*'      => Http::response(str_repeat('á', 50000), 500),
    ]);
    ['webhook' => $redirect] = makeWebhook(['call.ended'], ['url' => 'https://redirect.example.com/h']);
    ['webhook' => $big] = makeWebhook(['call.ended'], ['url' => 'https://big.example.com/h']);

    $deliverer = app(WebhookDeliverer::class);
    $r1 = $deliverer->attempt($redirect, 'call.ended', ['x' => 1], 'msg_a');
    $r2 = $deliverer->attempt($big, 'call.ended', ['x' => 1], 'msg_b');

    expect($r1['ok'])->toBeFalse()->and($r1['log']->error)->toContain('Redirección')
        ->and(strlen($r2['log']->response_body))->toBeLessThanOrEqual(WebhookDeliverer::MAX_RESPONSE_BYTES)
        ->and(mb_check_encoding($r2['log']->response_body, 'UTF-8'))->toBeTrue();
    Http::assertSentCount(2);
});

it('blocks delivery when DNS now points to a private address (checked on every delivery)', function () {
    Http::fake();
    ['webhook' => $webhook] = makeWebhook(['call.ended']);
    fakeDns(['hooks.example.com' => ['10.0.0.5']]);

    $result = app(WebhookDeliverer::class)->attempt($webhook, 'call.ended', [], 'msg_x', 1, 7);

    expect($result['ok'])->toBeFalse()->and($result['retryable'])->toBeFalse()
        ->and($result['log']->status)->toBe('failed')->and($result['log']->error)->toContain('privada');
    Http::assertNothingSent();
});

it('retries with the 1m/5m/30m/2h/6h/12h backoff, same webhook-id, and records the final failure', function () {
    Http::fake(['*' => Http::response('down', 503)]);
    ['webhook' => $webhook] = makeWebhook(['call.ended']);
    $job = new DispatchWebhookJob($webhook, 'call.ended', ['type' => 'call.ended'], 'msg_same');

    expect($job->backoff())->toBe([60, 300, 1800, 7200, 21600, 43200])->and($job->tries)->toBe(7);

    $queueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    $queueJob->shouldReceive('attempts')->andReturn(1, 7);
    $job->setJob($queueJob);

    expect(fn () => $job->handle(app(WebhookDeliverer::class)))->toThrow(RuntimeException::class);
    $first = WebhookLog::sole();
    expect($first->status)->toBe('retrying')->and($first->next_retry_at->diffInSeconds(now(), true))->toBeGreaterThan(55);

    $job->handle(app(WebhookDeliverer::class)); // 7th (last) attempt: no exception
    $last = WebhookLog::query()->latest('id')->first();
    expect($last->status)->toBe('failed')->and($last->attempts)->toBe(7)
        ->and(WebhookLog::pluck('message_id')->unique()->all())->toBe(['msg_same']);
});

it('disables after 24 h of continuous failure and sends the webhook.disabled notice', function () {
    $status = 500;
    Http::fake(function () use (&$status) {
        return Http::response('x', $status);
    });
    ['webhook' => $webhook] = makeWebhook(['call.ended']);
    $webhook->update(['created_by' => 'user-1']);
    $deliverer = app(WebhookDeliverer::class);

    $deliverer->attempt($webhook, 'call.ended', [], 'msg_1', 1, 7);
    expect($webhook->fresh()->failing_since)->not->toBeNull()->and($webhook->fresh()->active)->toBeTrue();

    Queue::fake();
    $this->travel(25)->hours();
    $deliverer->attempt($webhook->fresh(), 'call.ended', [], 'msg_2', 1, 7);

    $fresh = $webhook->fresh();
    expect($fresh->active)->toBeFalse()->and($fresh->disabled_at)->not->toBeNull()->and($fresh->disabled_reason)->toContain('24 h');
    Queue::assertPushed(DeliverNotice::class, fn ($job) => $job->notice->type === 'webhook.disabled' && $job->notice->recipients === ['user-1']);

    // A success resets the failure window.
    $status = 200;
    $deliverer->attempt($fresh, 'call.ended', [], 'msg_3');
    expect($webhook->fresh()->failing_since)->toBeNull();
});

it('rotation signs with both secrets for 24 h, then only the new one', function () {
    Http::fake(['*' => Http::response('ok')]);
    ['webhook' => $webhook, 'secret' => $old] = makeWebhook(['call.ended']);
    ['secret' => $new] = (new RotateWebhookSecret($webhook))->execute();

    TestCallEvent::dispatch('c_1');
    Http::assertSent(function (HttpRequest $r) use ($old, $new) {
        [$id, $ts, $sig] = [$r->header('webhook-id')[0], (int) $r->header('webhook-timestamp')[0], $r->header('webhook-signature')[0]];

        return count(explode(' ', $sig)) === 2
            && StandardWebhookSigner::verify($new, $id, $ts, $r->body(), $sig)
            && StandardWebhookSigner::verify($old, $id, $ts, $r->body(), $sig);
    });

    $this->travel(25)->hours();
    $result = app(WebhookDeliverer::class)->attempt($webhook->fresh(), 'call.ended', ['a' => 1], 'msg_r');
    expect(explode(' ', $result['log']->request_headers['webhook-signature']))->toHaveCount(1);
});

it('validates URL, events and scopes on create', function () {
    expect(fn () => makeWebhook(['call.ended'], ['url' => 'https://192.168.0.10/h']))->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(fn () => makeWebhook(['nope.*']))->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(fn () => makeWebhook(['call.ended'], ['scopes' => ['admin:all']]))->toThrow(\Illuminate\Validation\ValidationException::class);

    ['webhook' => $webhook, 'secret' => $secret] = makeWebhook(['call.*', 'person.created']);
    $raw = \Illuminate\Support\Facades\DB::table('webhooks')->where('id', $webhook->id)->first();

    expect($raw->signing_secret)->not->toContain($secret)          // encrypted at rest
        ->and($raw->secret)->toBe('')
        ->and(json_encode($webhook->toApiArray()))->not->toContain($secret);
});

it('with the sync queue a failing receiver never breaks the request that emitted the event', function () {
    Http::fake(['*' => Http::response('down', 500)]);
    makeWebhook(['call.ended']);

    TestCallEvent::dispatch('c_9');

    expect(WebhookLog::sole()->response_status)->toBe(500);
});
