<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Innertia\Webhooks\Models\WebhookLog;

uses(RefreshDatabase::class);

it('is loaded with the app migrations and is idempotent (re-runnable)', function () {
    expect(Schema::hasColumns('webhooks', ['signing_secret', 'previous_secret', 'organization_id', 'failing_since']))->toBeTrue()
        ->and(Schema::hasColumns('webhook_logs', ['message_id', 'status', 'duration_ms']))->toBeTrue()
        ->and(Schema::hasColumns('api_keys', ['prefix', 'organization_id', 'last_used_ip', 'rotated_to_id']))->toBeTrue()
        ->and(Schema::hasTable('api_request_logs'))->toBeTrue()
        ->and(Schema::hasTable('api_idempotency_keys'))->toBeTrue();

    $migration = require __DIR__ . '/../../database/migrations/integrations/2026_11_19_000002_create_public_api_tables.php';
    $migration->up();
    $migration->up();

    expect(Schema::hasTable('api_request_logs'))->toBeTrue();
});

it('prunes logs older than the retention', function () {
    \Innertia\Webhooks\Models\Webhook::create(['url' => 'https://x.example.com', 'events' => ['*'], 'secret' => 's', 'active' => true]);
    $old = WebhookLog::create(['webhook_id' => 1, 'event_key' => 'a', 'payload' => [], 'attempts' => 1]);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    WebhookLog::create(['webhook_id' => 1, 'event_key' => 'b', 'payload' => [], 'attempts' => 1]);

    Artisan::call('innertia:integrations:prune');

    expect(WebhookLog::pluck('event_key')->all())->toBe(['b']);
});
