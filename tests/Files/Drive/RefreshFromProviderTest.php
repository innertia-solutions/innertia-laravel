<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Drive\UseCases\ImportFromDrive;
use Innertia\Files\Drive\UseCases\RefreshFromProvider;
use Innertia\Files\Imports\Enums\FileImportKind;
use Innertia\Files\Imports\Enums\FileImportStatus;
use Innertia\Files\Imports\Models\FileImport;
use Innertia\Files\Models\File;

beforeEach(function () {
    require_once __DIR__ . '/helpers.php';
    $this->drive = driveTestBoot();
});

afterEach(fn () => driveTestShutdown());

function importOne(string $id): File
{
    $import = FileImport::create(['kind' => FileImportKind::Import, 'provider' => 'google_drive', 'source_items' => [$id]]);
    (new ImportFromDrive($import->id))->execute();

    return File::sole();
}

function runRefresh(File $file): FileImport
{
    $import = FileImport::create([
        'kind' => FileImportKind::Refresh, 'provider' => 'google_drive',
        'source_items' => [$file->external_id], 'file_id' => $file->id,
    ]);
    (new RefreshFromProvider($import->id))->execute();

    return $import->fresh();
}

it('brings the new Drive revision as a new version', function () {
    $this->drive->file('f1', 'a.txt', 'uno');
    $file = importOne('f1');
    $this->drive->edit('f1', 'dos');

    $import = runRefresh($file);

    expect($import->status)->toBe(FileImportStatus::Done);
    expect($import->updated)->toBe(1);
    expect($file->fresh()->version)->toBe(2);
    expect(Storage::disk('local')->get($file->fresh()->path))->toBe('dos');
});

it('reports unchanged when Drive did not change', function () {
    $this->drive->file('f1', 'a.txt', 'uno');
    $file = importOne('f1');

    expect(runRefresh($file)->unchanged)->toBe(1);
});

it('fails clearly and leaves the file intact when the Drive item is gone', function () {
    $this->drive->file('f1', 'a.txt', 'uno');
    $file = importOne('f1');
    $this->drive->remove('f1');

    $import = runRefresh($file);

    expect($import->status)->toBe(FileImportStatus::Failed);
    expect($import->error)->toContain('no existe');
    expect($file->fresh()->version)->toBe(1);
});

it('fails clearly when the file was not imported from Drive', function () {
    Storage::disk('local')->put('x.txt', 'x');
    $file = File::create(['disk' => 'local', 'path' => 'x.txt', 'original_name' => 'x.txt', 'size' => 1]);

    $import = runRefresh($file);

    expect($import->status)->toBe(FileImportStatus::Failed);
    expect($import->error)->toContain('no fue importado');
});

it('fails with the reason, without throwing, when the owner disabled downloads', function () {
    $this->drive->file('f1', 'a.txt', 'uno');
    $file = importOne('f1');
    $this->drive->edit('f1', 'dos');

    $this->drive->downloadDisabled[] = 'f1';

    $import = runRefresh($file);

    expect($import->status)->toBe(FileImportStatus::Failed);
    expect($import->error)->not->toBeEmpty();
    expect($file->fresh()->version)->toBe(1);
});
