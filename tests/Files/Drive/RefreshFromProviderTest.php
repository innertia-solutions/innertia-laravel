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

it('marks a still-running refresh as failed when the job dies', function () {
    $import = FileImport::create(['kind' => FileImportKind::Refresh, 'provider' => 'google_drive', 'source_items' => ['a']]);
    $import->markRunning('t');

    (new RefreshFromProvider($import->id))->failed(new \RuntimeException('timeout'));

    expect($import->fresh()->status)->toBe(FileImportStatus::Failed);
    expect($import->fresh()->error)->toBe('El proceso se interrumpió: timeout');
});

it('fails with the reason, without throwing, when the file or export got too large', function () {
    $this->drive->file('f1', 'a.txt', 'uno');
    $file = importOne('f1');
    config()->set('innertia.files.drive.max_file_size', 3);
    $this->drive->edit('f1', 'demasiado');

    $import = runRefresh($file);

    expect($import->status)->toBe(FileImportStatus::Failed);
    expect($import->error)->not->toBeEmpty();

    $this->drive->native('d1', 'Acta', 'document', [DOCX => 'D']);
    $file->forceDelete();
    $native = importOne('d1');
    $this->drive->edit('d1', 'E');
    $this->drive->exportTooLarge[] = 'd1';

    $import = runRefresh($native);

    expect($import->status)->toBe(FileImportStatus::Failed);
    expect($native->fresh()->version)->toBe(1);
});

it('fails clearly when the file is in the trash', function () {
    $this->drive->file('f1', 'a.txt', 'uno');
    $file = importOne('f1');
    $file->delete();

    $import = runRefresh($file);

    expect($import->status)->toBe(FileImportStatus::Failed);
    expect($import->error)->toBe('El archivo está en la papelera.');
});

it('claims the refresh atomically: a worker that lost the race does nothing', function () {
    $this->drive->file('f1', 'a.txt', 'uno');
    $file = importOne('f1');
    $this->drive->edit('f1', 'dos');
    $import = FileImport::create(['kind' => FileImportKind::Refresh, 'provider' => 'google_drive', 'source_items' => ['f1'], 'file_id' => $file->id]);

    $raced = false;
    \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $sql) use (&$raced, $import) {
        if (! $raced && str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'file_imports')) {
            $raced = true;
            \Illuminate\Support\Facades\DB::table('file_imports')->where('id', $import->id)->update(['status' => 'running', 'trace_id' => 'otro']);
        }
    });

    (new RefreshFromProvider($import->id))->execute();

    expect($file->fresh()->version)->toBe(1);
    expect($import->fresh()->trace_id)->toBe('otro');
});

it('never exposes an internal error message when the refresh fails unexpectedly', function () {
    $this->drive->file('f1', 'a.txt', 'uno');
    $file = importOne('f1');
    $this->drive->edit('f1', 'dos');
    $this->drive->failures['f1'] = new \RuntimeException('SQLSTATE[42S22]: Column not found: secreto');
    $import = FileImport::create(['kind' => FileImportKind::Refresh, 'provider' => 'google_drive', 'source_items' => ['f1'], 'file_id' => $file->id]);

    expect(fn () => (new RefreshFromProvider($import->id))->execute())->toThrow(\RuntimeException::class);

    expect($import->fresh()->status)->toBe(FileImportStatus::Failed);
    expect($import->fresh()->error)->toBe('Error interno al importar el elemento (ver registro con el trace_id).');
});

it('marks the refresh failed when the job dies without an exception', function () {
    $import = FileImport::create(['kind' => FileImportKind::Refresh, 'provider' => 'google_drive', 'source_items' => ['a']]);
    $import->markRunning('t');

    (new RefreshFromProvider($import->id))->failed(null);

    expect($import->fresh()->error)->toBe('El proceso se interrumpió: sin detalle');
});
