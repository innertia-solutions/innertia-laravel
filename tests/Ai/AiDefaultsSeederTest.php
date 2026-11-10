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

it('seeds minimax (chat/vision) + openai (embedding default), assignments, default collection and mcp server', function () {
    (new AiDefaultsSeeder)->run();

    // Provider
    expect(AiProvider::count())->toBe(2);
    $openai = AiProvider::where('key', 'openai')->first();
    expect($openai->type)->toBe('openai')
        ->and(AiModel::where('provider_id', $openai->id)->pluck('key')->all())->toBe(['text-embedding-3-small', 'gpt-4.1', 'whisper-1', 'gpt-4o-transcribe']);
    $minimax = AiProvider::where('key', 'minimax')->first();
    expect($minimax)->not->toBeNull()
        ->and($minimax->type)->toBe('openai-compatible')
        ->and($minimax->base_url)->toBe('https://api.minimax.io/v1');

    // Models
    expect(AiModel::where('provider_id', $minimax->id)->count())->toBe(4);
    $modelKeys = AiModel::where('provider_id', $minimax->id)->pluck('key')->sort()->values()->all();
    expect($modelKeys)->toContain('MiniMax-M3.1-Flash')
        ->toContain('MiniMax-M3')
        ->toContain('embo-01')
        ->toContain('asr-1.0');

    // Assignments
    expect(AiAssignment::count())->toBe(4);

    $chatAssignment = AiAssignment::where('use', 'chat')->first();
    expect($chatAssignment)->not->toBeNull()
        ->and($chatAssignment->model)->toBe('MiniMax-M3.1-Flash')
        ->and($chatAssignment->provider_id)->toBe($minimax->id);

    $assistantAssignment = AiAssignment::where('use', 'assistant')->first();
    expect($assistantAssignment)->not->toBeNull()
        ->and($assistantAssignment->model)->toBe('MiniMax-M3.1-Flash')
        ->and($assistantAssignment->provider_id)->toBe($minimax->id);

    $visionAssignment = AiAssignment::where('use', 'vision')->first();
    expect($visionAssignment)->not->toBeNull()
        ->and($visionAssignment->model)->toBe('MiniMax-M3.1-Flash')
        ->and($visionAssignment->provider_id)->toBe($minimax->id);

    $embeddingAssignment = AiAssignment::where('use', 'embedding')->first();
    expect($embeddingAssignment)->not->toBeNull()
        ->and($embeddingAssignment->model)->toBe('text-embedding-3-small')
        ->and($embeddingAssignment->provider_id)->toBe($openai->id);

    // RAG Collection
    expect(RagCollection::count())->toBe(1);
    $collection = RagCollection::where('key', 'default')->first();
    expect($collection)->not->toBeNull()
        ->and($collection->embedding_model)->toBe('text-embedding-3-small')
        ->and($collection->dimension)->toBe(1536)
        ->and($collection->embedding_provider_id)->toBe($openai->id);

    // MCP Server
    expect(McpServer::count())->toBe(1);
    $mcp = McpServer::where('key', 'question-bank')->first();
    expect($mcp)->not->toBeNull()
        ->and($mcp->enabled)->toBeTrue();
});

it('is idempotent', function () {
    (new AiDefaultsSeeder)->run();
    (new AiDefaultsSeeder)->run();

    expect(AiProvider::count())->toBe(2);
    expect(AiModel::count())->toBe(8);
    expect(AiAssignment::count())->toBe(4);
    expect(RagCollection::count())->toBe(1);
    expect(McpServer::count())->toBe(1);
});

it('un proveedor openai nuevo recibe su modelo de embedding por defecto (idempotente)', function () {
    $p = AiProvider::create(['key' => 'openai-x', 'name' => 'OpenAI', 'type' => 'openai', 'base_url' => 'https://api.openai.com/v1', 'active' => true]);

    expect(\Innertia\Ai\ProviderPresets::ensureDefaultModels($p))->toBe(4)
        ->and(\Innertia\Ai\ProviderPresets::ensureDefaultModels($p))->toBe(0);
    $m = AiModel::where('provider_id', $p->id)->where('key', 'text-embedding-3-small')->sole();
    expect($m->key)->toBe('text-embedding-3-small')->and($m->capabilities)->toBe(['embedding']);
});

it('un tipo sin presets no recibe modelos', function () {
    $p = AiProvider::create(['key' => 'otro', 'name' => 'Otro', 'type' => 'openai-compatible', 'active' => true]);

    expect(\Innertia\Ai\ProviderPresets::ensureDefaultModels($p))->toBe(0);
});
