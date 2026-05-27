<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->migrationsDir = sys_get_temp_dir() . '/innertia-dirs-install-' . uniqid();
    File::ensureDirectoryExists($this->migrationsDir);
});

afterEach(function () {
    if (isset($this->migrationsDir) && File::isDirectory($this->migrationsDir)) {
        File::deleteDirectory($this->migrationsDir);
    }
});

it('aborts when directories feature is disabled', function () {
    config()->set('innertia.directories.enabled', false);

    $exit = $this->artisan('innertia:directories:install', ['--path' => $this->migrationsDir])
        ->expectsOutputToContain('directories.enabled')
        ->run();

    expect($exit)->not->toBe(0);
});

it('generates migration with directories table when enabled', function () {
    config()->set('innertia.directories.enabled', true);

    $this->artisan('innertia:directories:install', ['--path' => $this->migrationsDir])
        ->assertSuccessful();

    $files = glob($this->migrationsDir . '/*_create_directories_table*.php');
    expect($files)->toHaveCount(1);

    $contents = file_get_contents($files[0]);
    expect($contents)->toContain("Schema::create('directories'");
    expect($contents)->toContain("->string('path', 4096)");
    expect($contents)->toContain("->uuid('parent_id')");
    expect($contents)->toContain("->uuid('trash_group_id')");
    expect($contents)->toContain("->softDeletes()");
    expect($contents)->toContain('directories_name_unique');  // partial unique index
});

it('migration adds directory_id to files when files table exists', function () {
    config()->set('innertia.directories.enabled', true);

    $this->artisan('innertia:directories:install', ['--path' => $this->migrationsDir])
        ->assertSuccessful();

    $files = glob($this->migrationsDir . '/*_create_directories_table*.php');
    $contents = file_get_contents($files[0]);

    expect($contents)->toContain("Schema::hasColumn('files', 'directory_id')");
    expect($contents)->toContain("\$table->uuid('directory_id')->nullable()");
});

it('does not overwrite without --force', function () {
    config()->set('innertia.directories.enabled', true);

    $this->artisan('innertia:directories:install', ['--path' => $this->migrationsDir])->assertSuccessful();
    sleep(1);
    $this->artisan('innertia:directories:install', ['--path' => $this->migrationsDir])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(glob($this->migrationsDir . '/*_create_directories_table*.php'))->toHaveCount(1);
});
