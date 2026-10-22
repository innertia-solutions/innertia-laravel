<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Innertia\Ai\Facades\Ai;
use Innertia\Ai\Models\UsageEvent;

uses(RefreshDatabase::class);

// ─── Schema setup ──────────────────────────────────────────────────────────

beforeEach(function () {
    config()->set('innertia.ai.enabled', true);

    $this->migrationsDir = sys_get_temp_dir() . '/innertia-ai-usage-' . uniqid();
    File::ensureDirectoryExists($this->migrationsDir);

    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])->assertSuccessful();

    $file = glob($this->migrationsDir . '/*_create_ai_tables*.php')[0];
    $this->aiMigration = require $file;
    $this->aiMigration->up();
});

afterEach(function () {
    $this->aiMigration->down();

    if (isset($this->migrationsDir) && File::isDirectory($this->migrationsDir)) {
        File::deleteDirectory($this->migrationsDir);
    }
});

// ─── Tests ─────────────────────────────────────────────────────────────────

it('records a usage event and computes total tokens', function () {
    Ai::usage()->record('chat', 'prov-1', 'MiniMax-M2', 100, 40);

    $event = UsageEvent::first();

    expect($event)->not->toBeNull()
        ->and($event->total_tokens)->toBe(140)
        ->and($event->use)->toBe('chat')
        ->and($event->prompt_tokens)->toBe(100)
        ->and($event->completion_tokens)->toBe(40);
});

it('aggregates total tokens, optionally by use', function () {
    Ai::usage()->record('chat', null, null, 10, 0);
    Ai::usage()->record('chat', null, null, 20, 0);
    Ai::usage()->record('embedding', null, null, 5, 0);

    expect(Ai::usage()->totalTokens('chat'))->toBe(30)
        ->and(Ai::usage()->totalTokens('embedding'))->toBe(5)
        ->and(Ai::usage()->totalTokens())->toBe(35);
});
