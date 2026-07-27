<?php

use Innertia\Backups\Contracts\BackupDriver;
use Innertia\Backups\Exceptions\SafetyBackupFailedException;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\UseCases\RestoreBackup;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
});

afterEach(fn () => innertiaBackupsMigrateDown());

function bindRestoreDriver(callable $create, callable $restore): void
{
    app()->bind(BackupDriver::class, fn () => new class($create, $restore) implements BackupDriver {
        public function __construct(private $create, private $restore) {}
        public function create(Backup $backup): void { ($this->create)($backup); }
        public function restore(Backup $backup): void { ($this->restore)($backup); }
        public function extension(): string { return 'dump'; }
    });
}

it('creates a safety backup before restoring', function () {
    $order = [];
    bindRestoreDriver(
        create: function (Backup $b) use (&$order) {
            $order[] = 'safety:' . ($b->is_safety ? 'yes' : 'no');
            $b->update(['path' => 'backups/safety.dump', 'size_bytes' => 5, 'checksum' => 's']);
        },
        restore: function (Backup $b) use (&$order) { $order[] = 'restore:' . $b->id; },
    );
    $target = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'path' => 'backups/target.dump', 'status' => 'completed',
    ]);

    (new RestoreBackup($target))->execute();

    expect(Backup::where('is_safety', true)->count())->toBe(1);
    expect($order[0])->toBe('safety:yes');
    expect($order[1])->toBe('restore:' . $target->id);
});

it('aborts the restore when the safety backup fails', function () {
    $restoreCalled = false;
    bindRestoreDriver(
        create: function (Backup $b) { throw new RuntimeException('disk full'); },
        restore: function (Backup $b) use (&$restoreCalled) { $restoreCalled = true; },
    );
    $target = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'path' => 'backups/target.dump', 'status' => 'completed',
    ]);

    expect(fn () => (new RestoreBackup($target))->execute())
        ->toThrow(SafetyBackupFailedException::class);

    expect($restoreCalled)->toBeFalse();
    expect(Backup::where('is_safety', true)->first()->status)->toBe('failed');
});
