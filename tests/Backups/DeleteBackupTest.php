<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\UseCases\DeleteBackup;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
    Storage::fake('backups');
});

afterEach(fn () => innertiaBackupsMigrateDown());

it('removes the artifact from disk and the record', function () {
    Storage::disk('backups')->put('backups/x.dump', 'data');
    $backup = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'path' => 'backups/x.dump', 'status' => 'completed',
    ]);

    (new DeleteBackup($backup))->execute();

    Storage::disk('backups')->assertMissing('backups/x.dump');
    expect(Backup::find($backup->id))->toBeNull();
});

it('deletes the record even if the artifact is already gone', function () {
    $backup = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'path' => 'backups/missing.dump', 'status' => 'failed',
    ]);

    (new DeleteBackup($backup))->execute();

    expect(Backup::find($backup->id))->toBeNull();
});
