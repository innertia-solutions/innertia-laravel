<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Backups\Drivers\AppBackupDriver;
use Innertia\Backups\Models\Backup;

require_once __DIR__ . '/support/FakeProcessRunner.php';

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
    Storage::fake('backups');
    config()->set('database.connections.pgsql', [
        'driver' => 'pgsql', 'host' => 'db-host', 'port' => '5432',
        'database' => 'app_db', 'username' => 'app_user', 'password' => 'secret',
    ]);
});

afterEach(fn () => innertiaBackupsMigrateDown());

it('builds a pg_dump -Fc command with connection params', function () {
    $runner = new FakeProcessRunner();
    $driver = new AppBackupDriver($runner);
    $backup = Backup::create(['disk' => 'backups', 'driver' => 'app', 'mode' => 'app']);

    $driver->create($backup);

    $cmd = $runner->lastCommand();
    expect($cmd)->toContain('pg_dump');
    expect($cmd)->toContain('-Fc');
    expect($cmd)->toContain('-h')->toContain('db-host');
    expect($cmd)->toContain('-U')->toContain('app_user');
    expect($cmd)->toContain('-d')->toContain('app_db');
    expect(implode(' ', $cmd))->not->toContain('secret');
    expect($runner->calls[0]['env']['PGPASSWORD'])->toBe('secret');
});

it('stores the artifact on the dedicated disk with size and checksum', function () {
    $runner = new FakeProcessRunner();
    $driver = new AppBackupDriver($runner);
    $backup = Backup::create(['disk' => 'backups', 'driver' => 'app', 'mode' => 'app']);

    $driver->create($backup);

    expect($backup->path)->not->toBeNull();
    Storage::disk('backups')->assertExists($backup->path);
    expect($backup->size_bytes)->toBeGreaterThan(0);
    expect($backup->checksum)->toBe(hash('sha256', 'FAKE_PG_DUMP_ARTIFACT'));
});

it('throws when pg_dump fails', function () {
    $runner = new FakeProcessRunner();
    $runner->exitCode = 1;
    $runner->errorOutput = 'pg_dump: connection to server failed';
    $driver = new AppBackupDriver($runner);
    $backup = Backup::create(['disk' => 'backups', 'driver' => 'app', 'mode' => 'app']);

    expect(fn () => $driver->create($backup))
        ->toThrow(RuntimeException::class, 'pg_dump: connection to server failed');
});

it('builds a pg_restore command against the artifact', function () {
    $runner = new FakeProcessRunner();
    $driver = new AppBackupDriver($runner);
    Storage::disk('backups')->put('backups/x.dump', 'FAKE_PG_DUMP_ARTIFACT');
    $backup = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'path' => 'backups/x.dump', 'status' => 'completed',
    ]);

    $driver->restore($backup);

    $cmd = $runner->lastCommand();
    expect($cmd)->toContain('pg_restore');
    expect($cmd)->toContain('--clean')->toContain('--if-exists');
    expect($cmd)->toContain('-d')->toContain('app_db');
});
