<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Innertia\Ai\Database\Seeders\AiDefaultsSeeder;
use Innertia\Ai\Models\AiAssignment;
use Innertia\Ai\Models\AiModel;
use Innertia\Ai\Models\AiProvider;
use Innertia\Ai\Models\McpServer;
use Innertia\Ai\Models\RagCollection;

uses(RefreshDatabase::class);

// ─── Schema setup ──────────────────────────────────────────────────────────
// Same approach as ResolverTest: generate the migration via install command,
// require the file, and call ->up() before each test; ->down() after.

beforeEach(function () {
    config()->set('innertia.ai.enabled', true);

    $this->migrationsDir = sys_get_temp_dir() . '/innertia-ai-seeder-' . uniqid();
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

it('seeds the minimax provider, models, assignments, default collection and mcp server', function () {
    (new AiDefaultsSeeder)->run();

    // Provider
    expect(AiProvider::count())->toBe(1);
    $minimax = AiProvider::where('key', 'minimax')->first();
    expect($minimax)->not->toBeNull()
        ->and($minimax->type)->toBe('openai-compatible')
        ->and($minimax->base_url)->toBe('https://api.minimax.io/v1');

    // Models
    expect(AiModel::where('provider_id', $minimax->id)->count())->toBe(3);
    $modelKeys = AiModel::where('provider_id', $minimax->id)->pluck('key')->sort()->values()->all();
    expect($modelKeys)->toContain('MiniMax-M2')
        ->toContain('abab6.5v-chat')
        ->toContain('embo-01');

    // Assignments
    expect(AiAssignment::count())->toBe(4);

    $chatAssignment = AiAssignment::where('use', 'chat')->first();
    expect($chatAssignment)->not->toBeNull()
        ->and($chatAssignment->model)->toBe('MiniMax-M2')
        ->and($chatAssignment->provider_id)->toBe($minimax->id);

    $assistantAssignment = AiAssignment::where('use', 'assistant')->first();
    expect($assistantAssignment)->not->toBeNull()
        ->and($assistantAssignment->model)->toBe('MiniMax-M2')
        ->and($assistantAssignment->provider_id)->toBe($minimax->id);

    $visionAssignment = AiAssignment::where('use', 'vision')->first();
    expect($visionAssignment)->not->toBeNull()
        ->and($visionAssignment->model)->toBe('abab6.5v-chat')
        ->and($visionAssignment->provider_id)->toBe($minimax->id);

    $embeddingAssignment = AiAssignment::where('use', 'embedding')->first();
    expect($embeddingAssignment)->not->toBeNull()
        ->and($embeddingAssignment->model)->toBe('embo-01')
        ->and($embeddingAssignment->provider_id)->toBe($minimax->id);

    // RAG Collection
    expect(RagCollection::count())->toBe(1);
    $collection = RagCollection::where('key', 'default')->first();
    expect($collection)->not->toBeNull()
        ->and($collection->embedding_model)->toBe('embo-01')
        ->and($collection->dimension)->toBe(1536)
        ->and($collection->embedding_provider_id)->toBe($minimax->id);

    // MCP Server
    expect(McpServer::count())->toBe(1);
    $mcp = McpServer::where('key', 'question-bank')->first();
    expect($mcp)->not->toBeNull()
        ->and($mcp->enabled)->toBeTrue();
});

it('is idempotent', function () {
    (new AiDefaultsSeeder)->run();
    (new AiDefaultsSeeder)->run();

    expect(AiProvider::count())->toBe(1);
    expect(AiModel::count())->toBe(3);
    expect(AiAssignment::count())->toBe(4);
    expect(RagCollection::count())->toBe(1);
    expect(McpServer::count())->toBe(1);
});
