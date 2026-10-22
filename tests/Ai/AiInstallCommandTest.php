<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migrationsDir = sys_get_temp_dir() . '/innertia-ai-install-' . uniqid();
    File::ensureDirectoryExists($this->migrationsDir);
});

afterEach(function () {
    if (isset($this->migrationsDir) && File::isDirectory($this->migrationsDir)) {
        File::deleteDirectory($this->migrationsDir);
    }
});

it('aborts when ai feature is disabled', function () {
    config()->set('innertia.ai.enabled', false);

    $exit = $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])
        ->expectsOutputToContain('ai.enabled')
        ->run();

    expect($exit)->not->toBe(0);
});

it('generates the migration when feature is enabled', function () {
    config()->set('innertia.ai.enabled', true);

    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])
        ->assertSuccessful();

    $files = glob($this->migrationsDir . '/*_create_ai_tables*.php');
    expect($files)->toHaveCount(1);

    $contents = file_get_contents($files[0]);
    expect($contents)->toContain("Schema::create('ai_providers'");
    expect($contents)->toContain("Schema::create('ai_doc_chunks'");
    expect($contents)->toContain("Schema::create('ai_mcp_servers'");
    expect($contents)->toContain('getDriverName');
});

it('does not overwrite an existing migration without --force', function () {
    config()->set('innertia.ai.enabled', true);

    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])->assertSuccessful();
    $first = glob($this->migrationsDir . '/*_create_ai_tables*.php')[0];
    $originalMtime = filemtime($first);

    sleep(1);

    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(filemtime($first))->toBe($originalMtime);
});

it('regenerates the migration with --force', function () {
    config()->set('innertia.ai.enabled', true);

    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])->assertSuccessful();

    sleep(1);

    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir, '--force' => true])
        ->assertSuccessful();

    $files = glob($this->migrationsDir . '/*_create_ai_tables*.php');
    expect($files)->toHaveCount(2);
});

it('migrating the generated file creates all AI tables (sqlite)', function () {
    config()->set('innertia.ai.enabled', true);

    $this->artisan('innertia:ai:install', ['--path' => $this->migrationsDir])->assertSuccessful();

    $file = glob($this->migrationsDir . '/*_create_ai_tables*.php')[0];

    $migration = require $file;
    $migration->up();

    foreach ([
        'ai_providers',
        'ai_models',
        'ai_assignments',
        'ai_usage_events',
        'ai_rag_collections',
        'ai_doc_chunks',
        'ai_conversations',
        'ai_messages',
        'ai_mcp_servers',
        'ai_index_runs',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("table {$table} should exist");
    }

    // sqlite text-fallback branch: the embedding column must exist.
    expect(Schema::hasColumn('ai_doc_chunks', 'embedding'))->toBeTrue();

    $migration->down();
});
