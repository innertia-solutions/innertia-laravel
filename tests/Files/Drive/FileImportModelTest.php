<?php

use Innertia\Files\Imports\Enums\FileImportKind;
use Innertia\Files\Imports\Enums\FileImportStatus;
use Innertia\Files\Imports\Models\FileImport;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaFilesMigrateUp();
});

afterEach(fn () => innertiaFilesMigrateDown());

function newImport(array $attrs = []): FileImport
{
    return FileImport::create(array_merge([
        'kind'         => FileImportKind::Import,
        'provider'     => 'google_drive',
        'source_items' => ['abc'],
    ], $attrs));
}

it('starts pending with zero counters and casts json', function () {
    $import = newImport(['file_attributes' => ['owner_type' => 'platform']])->fresh();

    expect($import->status)->toBe(FileImportStatus::Pending);
    expect($import->kind)->toBe(FileImportKind::Import);
    expect($import->source_items)->toBe(['abc']);
    expect($import->file_attributes)->toBe(['owner_type' => 'platform']);
    expect($import->imported)->toBe(0);
    expect($import->native_format)->toBe('office');
    expect($import->visibility)->toBe('auth');
});

it('records items, increments counters and caps the detail list', function () {
    $import = newImport();

    $import->record('imported', ['external_id' => 'a', 'name' => 'a.txt']);
    $import->record('failed', ['external_id' => 'b', 'name' => 'b.txt', 'reason' => 'x']);
    $import->record('skipped', ['external_id' => 'c', 'name' => 'c', 'reason' => 'y']);
    $import->save();

    $fresh = $import->fresh();
    expect($fresh->imported)->toBe(1);
    expect($fresh->failed_count)->toBe(1);
    expect($fresh->skipped)->toBe(1);
    expect($fresh->items[1])->toBe(['result' => 'failed', 'external_id' => 'b', 'name' => 'b.txt', 'reason' => 'x']);

    $capped = newImport();
    for ($i = 0; $i < FileImport::ITEMS_CAP + 3; $i++) {
        $capped->record('imported', ['external_id' => "x$i"]);
    }
    expect($capped->imported)->toBe(FileImport::ITEMS_CAP + 3);
    expect(count($capped->items))->toBe(FileImport::ITEMS_CAP);
});

it('rejects an unknown result', function () {
    newImport()->record('weird', []);
})->throws(\InvalidArgumentException::class);

it('moves through running, done and failed', function () {
    $import = newImport();

    $import->markRunning('trace-1');
    expect($import->fresh()->status)->toBe(FileImportStatus::Running);
    expect($import->fresh()->trace_id)->toBe('trace-1');
    expect($import->fresh()->started_at)->not->toBeNull();

    $import->markDone();
    expect($import->fresh()->status)->toBe(FileImportStatus::Done);
    expect($import->fresh()->finished_at)->not->toBeNull();
    expect($import->fresh()->isFinished())->toBeTrue();

    $other = newImport();
    $other->markFailed('boom');
    expect($other->fresh()->status)->toBe(FileImportStatus::Failed);
    expect($other->fresh()->error)->toBe('boom');
});

it('keeps only allowed file attributes', function () {
    expect(FileImport::filterAttributes(['owner_type' => 'platform', 'owner_id' => null, 'path' => '/etc', 'disk' => 's3']))
        ->toBe(['owner_type' => 'platform', 'owner_id' => null]);
});

it('detects a running import past job_timeout + 300s as stale and fails it', function () {
    config()->set('innertia.files.drive.job_timeout', 600);
    $import = newImport();
    $import->markRunning('t');

    $this->travel(899)->seconds();
    expect($import->fresh()->isStale())->toBeFalse();
    expect($import->fresh()->markStaleAsFailed())->toBeFalse();

    $this->travel(2)->seconds();
    $fresh = $import->fresh();
    expect($fresh->isStale())->toBeTrue();
    expect($fresh->markStaleAsFailed())->toBeTrue();
    expect($fresh->status)->toBe(FileImportStatus::Failed);
    expect($import->fresh()->error)->toBe('El proceso no terminó (tiempo excedido).');
    expect($import->fresh()->finished_at)->not->toBeNull();

    $pending = newImport();
    expect($pending->isStale())->toBeFalse();
});

it('marks failed only while still running', function () {
    $running = newImport();
    $running->markRunning('t');
    $done = newImport();
    $done->markDone();

    $running->markFailedIfRunning('x');
    $done->markFailedIfRunning('x');

    expect($running->fresh()->status)->toBe(FileImportStatus::Failed);
    expect($running->fresh()->error)->toBe('x');
    expect($done->fresh()->status)->toBe(FileImportStatus::Done);
});
