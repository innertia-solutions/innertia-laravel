<?php

use Innertia\Backups\Models\Backup;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
});

afterEach(fn () => innertiaBackupsMigrateDown());

it('creates a backup row with a generated uuid and defaults', function () {
    $backup = Backup::create([
        'disk' => 'backups',
        'driver' => 'app',
        'mode' => 'app',
    ]);

    expect($backup->id)->toBeString()->toHaveLength(36);
    expect($backup->status)->toBe('pending');
    expect($backup->is_safety)->toBeFalse();
    expect(Backup::find($backup->id))->not->toBeNull();
});

it('casts is_safety and completed_at', function () {
    $backup = Backup::create([
        'disk' => 'backups', 'driver' => 'app', 'mode' => 'app',
        'is_safety' => true, 'completed_at' => now(),
    ]);

    expect($backup->is_safety)->toBeTrue();
    expect($backup->completed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
