<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Innertia\Ai\Events\AiUsageRecorded;
use Innertia\Ai\Facades\Ai;
use Innertia\Ai\Models\AiModel;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\UsageEvent;
use Innertia\Ai\Usage\UsageReport;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('innertia.ai.enabled', true);
    $this->migrationsDir = sys_get_temp_dir() . '/innertia-ai-cost-' . uniqid();
    File::ensureDirectoryExists($this->migrationsDir);
    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])->assertSuccessful();
    $this->aiMigration = require glob($this->migrationsDir . '/*_create_ai_tables*.php')[0];
    $this->aiMigration->up();

    $this->provider = AiProvider::create(['key' => 'openai', 'name' => 'OpenAI', 'type' => 'openai']);
    $this->model = AiModel::create([
        'provider_id' => $this->provider->id, 'key' => 'gpt-test', 'name' => 'GPT test',
        'price_input_per_mtok' => 2.0, 'price_output_per_mtok' => 8.0,
        'price_per_audio_minute' => 0.006, 'price_per_mchar_tts' => 15.0,
    ]);
});

afterEach(function () {
    $this->aiMigration->down();
    File::deleteDirectory($this->migrationsDir);
});

it('records a usage with feature, units and cost from the current model price', function () {
    Event::fake([AiUsageRecorded::class]);

    $event = Ai::recordUsage('call_summary', ['input_tokens' => 1_000_000, 'output_tokens' => 500_000], 'gpt-test');

    expect($event->feature)->toBe('call_summary')
        ->and($event->use)->toBe('call_summary')
        ->and($event->prompt_tokens)->toBe(1_000_000)
        ->and($event->total_tokens)->toBe(1_500_000)
        ->and($event->units)->toBe(['input_tokens' => 1_000_000, 'output_tokens' => 500_000])
        ->and($event->fresh()->cost_usd)->toBe(6.0)               // 2 + 0.5 × 8
        ->and($event->provider_id)->toBe($this->provider->id);
    Event::assertDispatched(AiUsageRecorded::class);
});

it('prices audio minutes and tts characters', function () {
    $audio = Ai::recordUsage('transcription', ['audio_seconds' => 90], 'gpt-test');
    $tts = Ai::recordUsage('tts', ['tts_chars' => 2000], 'gpt-test');

    expect($audio->fresh()->cost_usd)->toBe(0.009)           // 1.5 min × 0.006
        ->and($tts->fresh()->cost_usd)->toBe(0.03);           // 0.002 Mchar × 15
});

it('leaves cost null for an unknown or unpriced model and the price change is not retroactive', function () {
    $unknown = Ai::recordUsage('assistant', ['input_tokens' => 10], 'nope');
    $first = Ai::recordUsage('assistant', ['input_tokens' => 1_000_000], 'gpt-test');
    $this->model->update(['price_input_per_mtok' => 4.0]);
    $second = Ai::recordUsage('assistant', ['input_tokens' => 1_000_000], 'gpt-test');

    expect($unknown->fresh()->cost_usd)->toBeNull()
        ->and($first->fresh()->cost_usd)->toBe(2.0)
        ->and($second->fresh()->cost_usd)->toBe(4.0);
});

it('keeps the legacy record() API and now also computes the token cost', function () {
    $event = Ai::usage()->record('chat', $this->provider->id, 'gpt-test', 500_000, 0);

    expect($event->fresh()->cost_usd)->toBe(1.0)
        ->and($event->feature)->toBeNull()
        ->and(Ai::usage()->totalTokens('chat'))->toBe(500_000);
});

it('takes tenant and organization from the context argument', function () {
    $event = Ai::recordUsage('assistant', ['input_tokens' => 1], 'gpt-test', ['tenant_id' => '7', 'organization_id' => '3']);

    expect($event->tenant_id)->toBe('7')->and($event->organization_id)->toBe('3');
});

it('aggregates by feature, tenant and Santiago day, grouping old rows by use', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 02:30:00', 'UTC')); // 2026-11-09 23:30 Santiago (UTC-3)
    Ai::recordUsage('call_summary', ['input_tokens' => 1_000_000], 'gpt-test', ['tenant_id' => '1']);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-10 15:00:00', 'UTC'));
    Ai::recordUsage('call_summary', ['input_tokens' => 1_000_000], 'gpt-test', ['tenant_id' => '1']);
    Ai::recordUsage('tts', ['tts_chars' => 1_000_000], 'gpt-test', ['tenant_id' => '2']);
    // Pre-phase-C row: no feature, no cost, no tenant.
    UsageEvent::create(['use' => 'embedding', 'prompt_tokens' => 50, 'total_tokens' => 50, 'created_at' => now()]);

    $report = new UsageReport('2026-11-01', '2026-11-30');
    $totals = $report->totals();
    $features = collect($report->byFeature())->keyBy('feature');
    $daily = collect($report->daily())->keyBy('date');

    expect($totals['events'])->toBe(4)
        ->and($totals['cost_usd'])->toBe(19.0)
        ->and($totals['unpriced_events'])->toBe(1)
        ->and($features['tts']['cost_usd'])->toBe(15.0)
        ->and($features['call_summary']['events'])->toBe(2)
        ->and($features['embedding']['events'])->toBe(1)
        ->and($daily['2026-11-09']['cost_usd'])->toBe(2.0)
        ->and($daily['2026-11-10']['cost_usd'])->toBe(17.0)
        ->and(count($report->daily()))->toBe(30)
        ->and(collect($report->byTenant())->keyBy('tenant_id')['1']['cost_usd'])->toBe(4.0)
        ->and((new UsageReport('2026-11-01', '2026-11-30', '2'))->cost())->toBe(15.0);
});

it('serves and updates model prices and the usage report over http', function () {
    Route::prefix('p')->group(function () {
        \Innertia\Ai\Routes::platform();
        \Innertia\Ai\Routes::platformWrite();
    });

    $this->getJson('/p/ai/models/prices')->assertOk()
        ->assertJsonPath('data.0.key', 'gpt-test')
        ->assertJsonPath('data.0.prices.price_input_per_mtok', 2)
        ->assertJsonPath('data.0.provider.key', 'openai');

    $this->putJson("/p/ai/models/{$this->model->id}/prices", [
        'price_input_per_mtok' => 3.5, 'price_output_per_mtok' => null,
        'price_per_audio_minute' => null, 'price_per_mchar_tts' => null,
    ])->assertOk()->assertJsonPath('prices.price_input_per_mtok', 3.5);

    $this->putJson("/p/ai/models/{$this->model->id}/prices", ['price_input_per_mtok' => -1])->assertUnprocessable();

    Ai::recordUsage('assistant', ['input_tokens' => 1_000_000], 'gpt-test', ['tenant_id' => '9']);
    $this->getJson('/p/ai/usage/report?month=' . now('America/Santiago')->format('Y-m'))->assertOk()
        ->assertJsonPath('totals.cost_usd', 3.5)
        ->assertJsonPath('by_tenant.0.tenant_id', '9');
});

it('additive migration upgrades an old schema idempotently and backfills tenant from single-tenant users', function () {
    Schema::drop('ai_usage_events');
    Schema::create('ai_usage_events', function ($t) {
        $t->uuid('id')->primary();
        $t->uuid('provider_id')->nullable();
        $t->string('model')->nullable();
        $t->string('use');
        $t->unsignedInteger('prompt_tokens')->default(0);
        $t->unsignedInteger('completion_tokens')->default(0);
        $t->unsignedInteger('total_tokens')->default(0);
        $t->uuid('user_id')->nullable();
        $t->timestamp('created_at')->nullable();
    });
    Schema::dropIfExists('user_contexts');
    Schema::create('user_contexts', function ($t) {
        $t->id();
        $t->string('user_id');
        $t->string('context');
        $t->string('tenant_id')->nullable();
    });
    DB::table('user_contexts')->insert([
        ['user_id' => 'u-1', 'context' => 'backoffice', 'tenant_id' => '5'],
        ['user_id' => 'u-2', 'context' => 'backoffice', 'tenant_id' => '5'],
        ['user_id' => 'u-2', 'context' => 'technician', 'tenant_id' => '6'],
    ]);
    DB::table('ai_usage_events')->insert([
        ['id' => 'a0000000-0000-0000-0000-000000000001', 'use' => 'chat', 'user_id' => 'u-1', 'created_at' => now()],
        ['id' => 'a0000000-0000-0000-0000-000000000002', 'use' => 'chat', 'user_id' => 'u-2', 'created_at' => now()],
    ]);

    // Before migrating, the logger still works (writes only existing columns).
    Ai::usage()->record('chat', null, 'gpt-test', 10, 0);

    $migration = require __DIR__ . '/../../database/migrations/ai/2026_11_19_000010_add_ai_usage_cost_columns.php';
    $migration->up();
    $migration->up();

    expect(Schema::hasColumns('ai_usage_events', ['tenant_id', 'organization_id', 'feature', 'units', 'cost_usd']))->toBeTrue()
        ->and(Schema::hasColumns('ai_models', AiModel::PRICE_FIELDS))->toBeTrue()
        ->and(DB::table('ai_usage_events')->where('user_id', 'u-1')->value('tenant_id'))->toBe('5')
        ->and(DB::table('ai_usage_events')->where('user_id', 'u-2')->value('tenant_id'))->toBeNull()
        ->and(UsageEvent::count())->toBe(3);
});
