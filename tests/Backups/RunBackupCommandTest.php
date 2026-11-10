<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Models\Backup;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
    Storage::fake('backups');
});

afterEach(fn () => innertiaBackupsMigrateDown());

function fakeDriver(bool $fail = false): void
{
    app()->instance(BackupDriver::class, new class($fail) implements BackupDriver {
        public function __construct(private bool $fail) {}
        public function create(Backup $backup): void
        {
            if ($this->fail) {
                throw new RuntimeException('pg_dump: connection refused');
            }
            Storage::disk($backup->disk)->put("backups/{$backup->id}.dump", 'dump');
            $backup->update(['path' => "backups/{$backup->id}.dump", 'size_bytes' => 4]);
        }
        public function restore(Backup $backup): void {}
        public function extension(): string { return 'dump'; }
    });
}

it('creates a completed backup and prunes by retention', function () {
    fakeDriver();
    $old = Backup::create(['disk' => 'backups', 'driver' => 'app', 'mode' => 'app', 'status' => 'completed']);
    Backup::whereKey($old->id)->update(['created_at' => now()->subDays(60)]);

    $this->artisan('innertia:backups:run')->assertSuccessful();

    expect(Backup::count())->toBe(1);
    expect(Backup::first()->status)->toBe('completed');
    expect(Backup::find($old->id))->toBeNull();
});

it('fails the command and records the error when the dump fails', function () {
    fakeDriver(fail: true);

    $this->artisan('innertia:backups:run')->assertFailed();

    expect(Backup::first()->status)->toBe('failed');
    expect(Backup::first()->error)->toContain('connection refused');
});

it('skips when another backup is in progress', function () {
    fakeDriver();
    Backup::create(['disk' => 'backups', 'driver' => 'app', 'mode' => 'app', 'status' => 'running']);

    $this->artisan('innertia:backups:run')->assertSuccessful();

    expect(Backup::count())->toBe(1);
});
