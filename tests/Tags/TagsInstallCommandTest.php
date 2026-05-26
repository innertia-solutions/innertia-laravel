<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->migrationsDir = sys_get_temp_dir() . '/innertia-tags-install-' . uniqid();
    File::ensureDirectoryExists($this->migrationsDir);
});

afterEach(function () {
    if (isset($this->migrationsDir) && File::isDirectory($this->migrationsDir)) {
        File::deleteDirectory($this->migrationsDir);
    }
});

it('aborts when tags feature is disabled', function () {
    config()->set('innertia.tags.enabled', false);

    $exit = $this->artisan('innertia:tags:install', ['--path' => $this->migrationsDir])
        ->expectsOutputToContain('tags.enabled')
        ->run();

    expect($exit)->not->toBe(0);
});

it('generates the migration when feature is enabled', function () {
    config()->set('innertia.tags.enabled', true);

    $this->artisan('innertia:tags:install', ['--path' => $this->migrationsDir])
        ->assertSuccessful();

    $files = glob($this->migrationsDir . '/*_create_tags_tables*.php');
    expect($files)->toHaveCount(1);

    $contents = file_get_contents($files[0]);
    expect($contents)->toContain("Schema::create('tags'");
    expect($contents)->toContain("Schema::create('taggables'");
    expect($contents)->toContain("\$table->string('tenant_id')->nullable()");
    expect($contents)->toContain("\$table->unique(['tenant_id', 'slug']");
    expect($contents)->toContain("\$table->primary(['tag_id', 'taggable_type', 'taggable_id']");
});

it('does not overwrite an existing migration without --force', function () {
    config()->set('innertia.tags.enabled', true);

    $this->artisan('innertia:tags:install', ['--path' => $this->migrationsDir])->assertSuccessful();
    $first = glob($this->migrationsDir . '/*_create_tags_tables*.php')[0];
    $originalMtime = filemtime($first);

    sleep(1);

    $this->artisan('innertia:tags:install', ['--path' => $this->migrationsDir])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(filemtime($first))->toBe($originalMtime);
});

it('regenerates the migration with --force', function () {
    config()->set('innertia.tags.enabled', true);

    $this->artisan('innertia:tags:install', ['--path' => $this->migrationsDir])->assertSuccessful();

    sleep(1);

    $this->artisan('innertia:tags:install', ['--path' => $this->migrationsDir, '--force' => true])
        ->assertSuccessful();

    $files = glob($this->migrationsDir . '/*_create_tags_tables*.php');
    expect($files)->toHaveCount(2);
});
