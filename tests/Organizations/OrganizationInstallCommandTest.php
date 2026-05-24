<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    config()->set('innertia.organizations.enabled', true);
    config()->set('innertia.organizations.tables', ['documents', 'normativas']);
    $this->migrationsDir = sys_get_temp_dir() . '/innertia-org-install-' . uniqid();
    File::ensureDirectoryExists($this->migrationsDir);
    $this->app->useDatabasePath(dirname($this->migrationsDir));
    // We point the command at a custom path via option below.
});

afterEach(function () {
    if (isset($this->migrationsDir) && File::isDirectory($this->migrationsDir)) {
        File::deleteDirectory($this->migrationsDir);
    }
});

it('aborts when organizations.enabled is false', function () {
    config()->set('innertia.organizations.enabled', false);
    $exit = $this->artisan('innertia:organization:install', ['--path' => $this->migrationsDir])
        ->expectsOutputToContain('organizations.enabled')
        ->run();
    expect($exit)->not->toBe(0);
});

it('generates one consolidated migration with all declared tables + roles + model_roles', function () {
    $this->artisan('innertia:organization:install', ['--path' => $this->migrationsDir])
        ->assertSuccessful();

    $files = glob($this->migrationsDir . '/*_add_organization_id_*.php');
    expect($files)->toHaveCount(1);

    $contents = file_get_contents($files[0]);
    expect($contents)->toContain("Schema::table('documents'");
    expect($contents)->toContain("Schema::table('normativas'");
    expect($contents)->toContain("Schema::table('roles'");
    expect($contents)->toContain("Schema::table('model_roles'");
    expect($contents)->toContain("->after('tenant_id')");
    expect($contents)->toContain("['tenant_id', 'organization_id']");
});

it('does not overwrite an existing migration', function () {
    $this->artisan('innertia:organization:install', ['--path' => $this->migrationsDir])
        ->assertSuccessful();
    $first = glob($this->migrationsDir . '/*_add_organization_id_*.php')[0];
    $originalMtime = filemtime($first);

    sleep(1);

    $this->artisan('innertia:organization:install', ['--path' => $this->migrationsDir])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    expect(filemtime($first))->toBe($originalMtime);
});
