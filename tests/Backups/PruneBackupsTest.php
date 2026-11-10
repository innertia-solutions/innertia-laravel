<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Backups\Models\Backup;
use Innertia\Backups\UseCases\PruneBackups;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
    Storage::fake('backups');
});

afterEach(fn () => innertiaBackupsMigrateDown());

function makeBackup(int $daysAgo, string $status = 'completed', bool $safety = false, int $minutes = 0): Backup
{
    $b = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'status' => $status, 'is_safety' => $safety,
    ]);
    $b->update(['path' => "backups/{$b->id}.dump"]);
    Storage::disk('backups')->put($b->path, 'x');

    $at = now()->subDays($daysAgo)->subMinutes($minutes);
    Backup::whereKey($b->id)->update(['created_at' => $at, 'updated_at' => $at]);

    return $b->fresh();
}

it('deletes completed backups older than retention_days with their artifacts', function () {
    $recent = makeBackup(1);
    $old = makeBackup(40);

    $deleted = (new PruneBackups(retentionDays: 30, keep: 0))->execute();

    expect($deleted)->toBe(1);
    expect(Backup::find($old->id))->toBeNull();
    Storage::disk('backups')->assertMissing($old->path);
    expect(Backup::find($recent->id))->not->toBeNull();
});

it('keeps only the newest N completed backups', function () {
    $ids = collect(range(0, 4))->map(fn ($i) => makeBackup(0, minutes: $i * 60)->id);

    $deleted = (new PruneBackups(retentionDays: 0, keep: 3))->execute();

    expect($deleted)->toBe(2);
    expect(Backup::pluck('id')->sort()->values()->all())->toBe($ids->take(3)->sort()->values()->all());
});

it('never deletes the most recent completed backup', function () {
    $only = makeBackup(90);

    expect((new PruneBackups(retentionDays: 30, keep: 1))->execute())->toBe(0);
    expect(Backup::find($only->id))->not->toBeNull();
});

it('prunes old failed and safety backups but never in-flight ones', function () {
    makeBackup(1);
    $failed = makeBackup(40, status: 'failed');
    $safety = makeBackup(40, safety: true);
    $running = makeBackup(40, status: 'running');
    $freshFailed = makeBackup(2, status: 'failed');

    expect((new PruneBackups(retentionDays: 30, keep: 21))->execute())->toBe(2);

    expect(Backup::find($failed->id))->toBeNull();
    expect(Backup::find($safety->id))->toBeNull();
    expect(Backup::find($running->id))->not->toBeNull();
    expect(Backup::find($freshFailed->id))->not->toBeNull();
});

it('does not delete anything on dry run', function () {
    makeBackup(1);
    makeBackup(40);

    expect((new PruneBackups(retentionDays: 30, keep: 0, dryRun: true))->execute())->toBe(1);
    expect(Backup::count())->toBe(2);
});

it('uses the configured retention by default', function () {
    config(['innertia.backups.retention_days' => 10, 'innertia.backups.keep' => 0]);
    makeBackup(1);
    makeBackup(11);

    expect((new PruneBackups())->execute())->toBe(1);
});
