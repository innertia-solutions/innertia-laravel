<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Innertia\Ai\AiManager;
use Innertia\Ai\Facades\Ai;
use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\ProviderResolver;

uses(RefreshDatabase::class);

// ─── Schema setup ──────────────────────────────────────────────────────────
// Mirrors the approach used in AiInstallCommandTest: generate the migration via
// the install command into a temp dir, require it, and call ->up() before each
// test; ->down() after.

beforeEach(function () {
    config()->set('innertia.ai.enabled', true);

    $this->migrationsDir = sys_get_temp_dir() . '/innertia-ai-resolver-' . uniqid();
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

// ─── Helper ────────────────────────────────────────────────────────────────

function makeChatSetup(): array
{
    $provider = AiProvider::create([
        'key'      => 'minimax',
        'name'     => 'MiniMax',
        'type'     => 'openai-compatible',
        'base_url' => 'https://api.minimax.io/v1',
        'api_key'  => 'secret-key',
        'active'   => true,
    ]);

    $assignment = AiAssignment::create([
        'use'         => 'chat',
        'provider_id' => $provider->id,
        'model'       => 'MiniMax-M2',
        'active'      => true,
    ]);

    return [$provider, $assignment];
}

// ─── Tests ─────────────────────────────────────────────────────────────────

it('resolves the provider and model for a use', function () {
    [$provider] = makeChatSetup();

    $result = (new ProviderResolver)->for('chat');

    expect($result)->not->toBeNull()
        ->and($result['model'])->toBe('MiniMax-M2')
        ->and($result['provider']->key)->toBe($provider->key);
});

it('returns null when there is no active assignment for the use', function () {
    $result = (new ProviderResolver)->for('vision');

    expect($result)->toBeNull();
});

it('configureFor applies the laravel/ai openai-compatible config and returns the model', function () {
    makeChatSetup();

    $model = Ai::configureFor('chat');

    expect($model)->toBe('MiniMax-M2')
        ->and(config('ai.providers.openai-compatible.url'))->toBe('https://api.minimax.io/v1')
        ->and(config('ai.providers.openai-compatible.key'))->toBe('secret-key');
});

it('enabled() is true only when the chat provider has an api key', function () {
    // (a) provider WITH api_key → enabled() true
    [$provider] = makeChatSetup();

    expect(Ai::enabled())->toBeTrue();

    // (b) null api_key → enabled() false
    $provider->api_key = null;
    $provider->save();

    // Clear facade instance cache so it re-resolves
    Ai::clearResolvedInstances();

    expect(Ai::enabled())->toBeFalse();
});
