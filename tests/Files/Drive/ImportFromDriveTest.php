<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Drive\UseCases\ImportFromDrive;
use Innertia\Files\Imports\Enums\FileImportKind;
use Innertia\Files\Imports\Enums\FileImportStatus;
use Innertia\Files\Imports\Models\FileImport;
use Innertia\Files\Models\File;

beforeEach(function () {
    require_once __DIR__ . '/helpers.php';
    $this->drive = driveTestBoot();
});

afterEach(fn () => driveTestShutdown());

function runImport(array $ids, array $attrs = []): FileImport
{
    $import = FileImport::create(array_merge([
        'kind' => FileImportKind::Import, 'provider' => 'google_drive', 'source_items' => $ids, 'created_by' => 'u-1',
    ], $attrs));

    (new ImportFromDrive($import->id))->execute();

    return $import->fresh();
}

it('imports a folder tree, natives and binaries, recording counters and items', function () {
    $this->drive->folder('A', 'Auditoría');
    $this->drive->file('a1', 'acta.pdf', '%PDF', 'A', 'application/pdf');
    $this->drive->native('a2', 'Minuta', 'document', [DOCX => 'DOCX', 'application/pdf' => 'PDF'], 'A');
    $this->drive->folder('B', '2026', 'A');
    $this->drive->native('b1', 'Plan', 'spreadsheet', [XLSX => 'XLSX'], 'B');
    $this->drive->native('b2', 'Encuesta', 'form', [], 'B');

    $import = runImport(['A']);

    expect($import->status)->toBe(FileImportStatus::Done);
    expect($import->found)->toBe(4);
    expect([$import->imported, $import->skipped, $import->failed_count])->toBe([3, 1, 0]);
    expect($import->trace_id)->not->toBeNull();
    expect($import->finished_at)->not->toBeNull();

    $root = Directory::where('external_id', 'A')->sole();
    $sub = Directory::where('external_id', 'B')->sole();
    expect($sub->parent_id)->toBe($root->id);
    expect(File::where('directory_id', $root->id)->pluck('original_name')->all())->toEqualCanonicalizing(['acta.pdf', 'Minuta.docx']);
    expect(File::where('directory_id', $sub->id)->pluck('original_name')->all())->toBe(['Plan.xlsx']);

    $skipped = collect($import->items)->firstWhere('external_id', 'b2');
    expect($skipped['result'])->toBe('skipped');
    expect($skipped['reason'])->toBe('Sin formato exportable.');
    expect($skipped['path'])->toBe('Auditoría/2026');
});

it('honours the pdf format for natives', function () {
    $this->drive->native('d1', 'Acta', 'document', [DOCX => 'DOCX', 'application/pdf' => 'PDF']);

    runImport(['d1'], ['native_format' => 'pdf']);

    expect(File::sole()->original_name)->toBe('Acta.pdf');
});

it('places loose files in the target directory', function () {
    $this->drive->file('f1', 'a.txt', 'x');
    $target = (new \Innertia\Files\Directories\UseCases\CreateDirectory(null, 'Destino'))->execute();

    runImport(['f1'], ['target_directory_id' => $target->id]);

    expect(File::sole()->directory_id)->toBe($target->id);
});

it('re-importing updates instead of duplicating', function () {
    $this->drive->folder('A', 'A');
    $this->drive->file('a1', 'a.txt', 'uno', 'A');
    runImport(['A']);

    $this->drive->edit('a1', 'dos');
    $second = runImport(['A']);

    expect([$second->imported, $second->updated, $second->unchanged])->toBe([0, 1, 0]);
    expect(File::count())->toBe(1);
    expect(Directory::count())->toBe(1);
});

it('keeps going when one item fails and marks missing ids', function () {
    $this->drive->file('ok', 'ok.txt', 'x');
    $this->drive->file('bad', 'bad.txt', 'y');
    $this->drive->broken[] = 'bad';

    $import = runImport(['ok', 'bad', 'ghost']);

    expect($import->status)->toBe(FileImportStatus::Done);
    expect([$import->imported, $import->failed_count])->toBe([1, 2]);
    expect(collect($import->items)->firstWhere('external_id', 'ghost')['reason'])->toContain('no existe');
});

it('skips files over the size limit and natives over the export limit', function () {
    config()->set('innertia.files.drive.max_file_size', 3);
    $this->drive->file('big', 'big.txt', 'abcdef');
    $this->drive->native('d1', 'Acta', 'document', [DOCX => 'D']);
    $this->drive->exportTooLarge[] = 'd1';

    $import = runImport(['big', 'd1']);

    expect([$import->skipped, $import->failed_count])->toBe([2, 0]);
});

it('fails the whole import before writing when the selection is too big', function () {
    config()->set('innertia.files.drive.max_items', 1);
    $this->drive->file('a', 'a.txt', 'x');
    $this->drive->file('b', 'b.txt', 'y');

    $import = FileImport::create(['kind' => FileImportKind::Import, 'provider' => 'google_drive', 'source_items' => ['a', 'b']]);

    expect(fn () => (new ImportFromDrive($import->id))->execute())->toThrow(\Innertia\Files\Drive\Exceptions\DriveImportTooLargeException::class);
    expect($import->fresh()->status)->toBe(FileImportStatus::Failed);
    expect($import->fresh()->error)->toContain('máximo');
    expect(File::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('does not run twice', function () {
    $this->drive->file('a', 'a.txt', 'x');
    $import = runImport(['a']);

    (new ImportFromDrive($import->id))->execute();

    expect(File::count())->toBe(1);
    expect($import->fresh()->imported)->toBe(1);
});

it('configures the queue job from config', function () {
    config()->set('innertia.files.drive.job_timeout', 1234);

    $job = new ImportFromDrive('x');

    expect($job->timeout)->toBe(1234);
    expect($job->tries)->toBe(1);
});
