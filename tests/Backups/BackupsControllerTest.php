<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Innertia\Backups\Http\Controllers\BackupsController;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\UseCases\RestoreBackup;
use Innertia\Backups\UseCases\RunBackup;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
    Storage::fake('backups');
    config()->set('innertia.backups.disk', 'backups');
    config()->set('innertia.backups.mode', 'app');
    config()->set('database.connections.pgsql.database', 'app_db');
});

afterEach(fn () => innertiaBackupsMigrateDown());

it('store creates a pending backup and dispatches RunBackup', function () {
    Bus::fake();
    $res = (new BackupsController())->store(new Request());

    expect($res->getStatusCode())->toBe(201);
    $backup = Backup::first();
    expect($backup->status)->toBe('pending');
    expect($backup->disk)->toBe('backups');
    expect($backup->mode)->toBe('app');
    Bus::assertDispatched(RunBackup::class);
});

it('restore rejects an invalid confirmation token with 422', function () {
    $backup = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'path' => 'backups/x.dump', 'status' => 'completed',
    ]);

    $call = fn () => (new BackupsController())->restore(
        new Request(['confirmation' => 'WRONG']),
        $backup->id,
    );

    expect($call)->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('restore with the correct token (db name) dispatches RestoreBackup', function () {
    Bus::fake();
    $backup = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'path' => 'backups/x.dump', 'status' => 'completed',
    ]);

    $res = (new BackupsController())->restore(
        new Request(['confirmation' => 'app_db']),
        $backup->id,
    );

    expect($res->getStatusCode())->toBe(202);
    Bus::assertDispatched(RestoreBackup::class);
});

it('download returns 404 when the artifact is missing', function () {
    $backup = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'path' => 'backups/missing.dump', 'status' => 'completed',
    ]);

    expect(fn () => (new BackupsController())->download($backup->id))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
});
