<?php

use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\UseCases\RunBackup;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
});

afterEach(fn () => innertiaBackupsMigrateDown());

function bindBackupDriver(callable $create, ?callable $restore = null): void
{
    app()->bind(BackupDriver::class, fn () => new class($create, $restore) implements BackupDriver {
        public function __construct(private $create, private $restore) {}
        public function create(Backup $backup): void { ($this->create)($backup); }
        public function restore(Backup $backup): void { if ($this->restore) ($this->restore)($backup); }
        public function extension(): string { return 'dump'; }
    });
}

it('marks the backup completed on success', function () {
    bindBackupDriver(function (Backup $b) {
        $b->update(['path' => 'backups/x.dump', 'size_bytes' => 10, 'checksum' => 'abc']);
    });
    $backup = Backup::create(['disk' => 'backups', 'driver' => 'app', 'mode' => 'app']);

    (new RunBackup($backup))->execute();

    $backup->refresh();
    expect($backup->status)->toBe('completed');
    expect($backup->completed_at)->not->toBeNull();
    expect($backup->size_bytes)->toBe(10);
});

it('marks the backup failed and records the error', function () {
    bindBackupDriver(function (Backup $b) {
        throw new RuntimeException('boom');
    });
    $backup = Backup::create(['disk' => 'backups', 'driver' => 'app', 'mode' => 'app']);

    (new RunBackup($backup))->execute();

    $backup->refresh();
    expect($backup->status)->toBe('failed');
    expect($backup->error)->toBe('boom');
    expect($backup->status)->not->toBe('running');
});
