<?php

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Innertia\Backups\Http\Controllers\BackupsController;
use Innertia\Backups\Support\BackupSettings;
use Innertia\Backups\UseCases\PruneBackups;

beforeEach(function () {
    require_once __DIR__ . '/helpers/migrate.php';
    innertiaBackupsMigrateUp();
});

afterEach(fn () => innertiaBackupsMigrateDown());

function putSettings(array $body)
{
    $request = Request::create('/platform/backups/settings', 'PUT', $body);
    app()->instance('request', $request);

    return (new BackupsController())->updateSettings($request);
}

it('returns the default policy, always enabled, with next runs', function () {
    $data = (new BackupsController())->settings()->getData(true);

    expect($data['enabled'])->toBeTrue();
    expect($data['times'])->toBe(['03:00', '11:00', '19:00']);
    expect($data['timezone'])->toBe('America/Santiago');
    expect($data['retention_days'])->toBe(30);
    expect($data['keep'])->toBe(21);
    expect($data['next_runs'])->toHaveCount(3);
    expect($data['defaults']['times'])->toBe(['03:00', '11:00', '19:00']);
});

it('saves the policy in settings and the schedule/prune use it', function () {
    $data = putSettings([
        'times' => ['20:00', '08:00'], 'timezone' => 'America/Santiago',
        'retention_days' => 7, 'keep' => 10,
    ])->getData(true);

    expect($data['times'])->toBe(['08:00', '20:00']);
    expect(BackupSettings::times())->toBe(['08:00', '20:00']);
    expect(BackupSettings::retentionDays())->toBe(7);
    expect(BackupSettings::keep())->toBe(10);
});

it('rejects invalid times, timezone and retention', function (array $body) {
    putSettings($body + [
        'times' => ['03:00'], 'timezone' => 'America/Santiago', 'retention_days' => 30, 'keep' => 21,
    ]);
})->with([
    'hora inválida'     => [['times' => ['24:00']]],
    'sin horarios'      => [['times' => []]],
    'zona inexistente'  => [['timezone' => 'Mars/Base']],
    'retención 0 días'  => [['retention_days' => 0]],
    'keep 0'            => [['keep' => 0]],
])->throws(ValidationException::class);

it('prune uses the retention saved from /platform', function () {
    BackupSettings::save(['retention_days' => 10, 'keep' => 50]);
    $mk = function (int $days) {
        $b = \Innertia\Backups\Models\Backup::create(['disk' => 'backups', 'driver' => 'app', 'mode' => 'app', 'status' => 'completed']);
        \Innertia\Backups\Models\Backup::whereKey($b->id)->update(['created_at' => now()->subDays($days)]);
    };
    $mk(1);
    $mk(11);

    expect((new PruneBackups())->execute())->toBe(1);
});
