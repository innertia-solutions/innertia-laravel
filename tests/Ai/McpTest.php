<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Innertia\Ai\Mcp\EnsureMcpKey;
use Innertia\Ai\Mcp\McpRegistry;

uses(RefreshDatabase::class);

// ─── Schema setup ──────────────────────────────────────────────────────────

beforeEach(function () {
    config()->set('innertia.ai.enabled', true);

    $this->migrationsDir = sys_get_temp_dir() . '/innertia-ai-mcp-' . uniqid();
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

// ─── Registry tests ─────────────────────────────────────────────────────────

it('registers a server and fetches it by key', function () {
    $registry = new McpRegistry;

    $registry->register('question-bank', 'Banco de Preguntas');

    $server = $registry->get('question-bank');

    expect($server)->not->toBeNull()
        ->and($server->name)->toBe('Banco de Preguntas')
        ->and($server->enabled)->toBeTrue()
        ->and($server->api_key)->toBeNull();
});

it('generates and rotates the api key', function () {
    $registry = new McpRegistry;

    $registry->register('question-bank', 'Banco de Preguntas');

    $t1 = $registry->generateKey('question-bank');

    expect(str_starts_with($t1, 'mcp_'))->toBeTrue();
    expect($registry->get('question-bank')->api_key)->toBe($t1);

    $t2 = $registry->generateKey('question-bank');

    expect($t2)->not->toBe($t1);
    expect($registry->get('question-bank')->api_key)->toBe($t2);
});

// ─── Middleware tests ────────────────────────────────────────────────────────

it('returns 404 when the server does not exist', function () {
    Route::get('/_mcp-test', fn () => response()->json(['ok' => true]))
        ->middleware(EnsureMcpKey::class . ':question-bank');

    $this->getJson('/_mcp-test')->assertStatus(404);
});

it('returns 404 when the server is disabled', function () {
    (new McpRegistry)->register('question-bank', 'Banco de Preguntas', enabled: false);

    Route::get('/_mcp-test', fn () => response()->json(['ok' => true]))
        ->middleware(EnsureMcpKey::class . ':question-bank');

    $this->getJson('/_mcp-test')->assertStatus(404);
});

it('returns 503 when the server has no api key', function () {
    (new McpRegistry)->register('question-bank', 'Banco de Preguntas');

    Route::get('/_mcp-test', fn () => response()->json(['ok' => true]))
        ->middleware(EnsureMcpKey::class . ':question-bank');

    $this->getJson('/_mcp-test')->assertStatus(503);
});

it('returns 401 with a wrong or missing bearer', function () {
    $registry = new McpRegistry;
    $registry->register('question-bank', 'Banco de Preguntas');
    $registry->generateKey('question-bank');

    Route::get('/_mcp-test', fn () => response()->json(['ok' => true]))
        ->middleware(EnsureMcpKey::class . ':question-bank');

    // No Authorization header
    $this->getJson('/_mcp-test')->assertStatus(401);

    // Wrong token
    $this->getJson('/_mcp-test', ['Authorization' => 'Bearer wrong-token'])->assertStatus(401);
});

it('passes through with the correct bearer', function () {
    $registry = new McpRegistry;
    $registry->register('question-bank', 'Banco de Preguntas');
    $token = $registry->generateKey('question-bank');

    Route::get('/_mcp-test', fn () => response()->json(['ok' => true]))
        ->middleware(EnsureMcpKey::class . ':question-bank');

    $this->getJson('/_mcp-test', ['Authorization' => 'Bearer ' . $token])
        ->assertStatus(200)
        ->assertJson(['ok' => true]);
});
