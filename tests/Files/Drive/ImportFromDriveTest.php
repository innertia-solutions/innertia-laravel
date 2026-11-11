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

it('scopes the root dedupe by owner: another owner importing the same Drive file gets its own File', function () {
    $this->drive->file('f1', 'a.txt', 'uno');
    $ownerA = ['owner_type' => 'team', 'owner_id' => '11111111-1111-1111-1111-111111111111'];
    $ownerB = ['owner_type' => 'team', 'owner_id' => '22222222-2222-2222-2222-222222222222'];

    $first = runImport(['f1'], ['file_attributes' => $ownerA]);
    $this->drive->edit('f1', 'dos');
    $second = runImport(['f1'], ['file_attributes' => $ownerB]);
    $third = runImport(['f1']);

    expect([$first->imported, $second->imported, $second->updated, $third->imported])->toBe([1, 1, 0, 1]);
    expect(File::count())->toBe(3);
    $a = File::where('owner_id', $ownerA['owner_id'])->sole();
    expect($a->version)->toBe(1);
    expect(Storage::disk('local')->get($a->path))->toBe('uno');
    expect(File::whereNull('owner_type')->count())->toBe(1);
});

it('scopes the root folder dedupe by owner', function () {
    $this->drive->folder('A', 'A');
    $this->drive->file('a1', 'a.txt', 'x', 'A');
    $ownerA = ['owner_type' => 'team', 'owner_id' => '11111111-1111-1111-1111-111111111111'];
    $ownerB = ['owner_type' => 'team', 'owner_id' => '22222222-2222-2222-2222-222222222222'];

    runImport(['A'], ['file_attributes' => $ownerA]);
    $second = runImport(['A'], ['file_attributes' => $ownerB]);
    $again = runImport(['A'], ['file_attributes' => $ownerA]);

    expect($second->imported)->toBe(1);
    expect([$again->imported, $again->unchanged])->toBe([0, 1]);
    expect(Directory::count())->toBe(2);
    expect(Directory::where('owner_id', $ownerB['owner_id'])->sole()->external_id)->toBe('A');
    expect(File::count())->toBe(2);
});

it('applies the import owner and author to every created folder and to the creation event', function () {
    $events = [];
    \Illuminate\Support\Facades\Event::listen(\Innertia\Files\Directories\Events\DirectoryCreated::class, function ($e) use (&$events) {
        $events[] = [$e->directory->owner_type, $e->directory->owner_id, $e->performedBy?->getAuthIdentifier()];
    });
    $target = (new \Innertia\Files\Directories\UseCases\CreateDirectory(null, 'Destino'))->execute();
    $target->forceFill(['owner_type' => 'team', 'owner_id' => '33333333-3333-3333-3333-333333333333'])->save();
    $events = [];

    $this->drive->folder('A', 'A');
    $this->drive->folder('B', 'B', 'A');
    $this->drive->file('b1', 'b.txt', 'x', 'B');
    $owner = ['owner_type' => 'platform', 'owner_id' => '44444444-4444-4444-4444-444444444444'];

    runImport(['A'], ['target_directory_id' => $target->id, 'file_attributes' => $owner]);

    foreach (['A', 'B'] as $id) {
        $dir = Directory::where('external_id', $id)->sole();
        expect([$dir->owner_type, $dir->owner_id, $dir->created_by])->toBe(['platform', $owner['owner_id'], 'u-1']);
    }
    expect($events)->toBe([
        ['platform', $owner['owner_id'], 'u-1'],
        ['platform', $owner['owner_id'], 'u-1'],
    ]);
});

it('inherits the parent owner on folders when the import has no owner attributes', function () {
    $target = (new \Innertia\Files\Directories\UseCases\CreateDirectory(null, 'Destino'))->execute();
    $target->forceFill(['owner_type' => 'team', 'owner_id' => '33333333-3333-3333-3333-333333333333'])->save();
    $this->drive->folder('A', 'A');
    $this->drive->file('a1', 'a.txt', 'x', 'A');

    runImport(['A'], ['target_directory_id' => $target->id]);
    $again = runImport(['A'], ['target_directory_id' => $target->id]);

    expect(Directory::where('external_id', 'A')->sole()->owner_id)->toBe('33333333-3333-3333-3333-333333333333');
    expect($again->unchanged)->toBe(1);
});

it('fails without writing when the target directory vanished or was trashed', function () {
    $this->drive->file('f1', 'a.txt', 'x');
    $trashed = (new \Innertia\Files\Directories\UseCases\CreateDirectory(null, 'Destino'))->execute();
    $trashed->delete();

    $gone = runImport(['f1'], ['target_directory_id' => '00000000-0000-0000-0000-000000000000']);
    $inTrash = runImport(['f1'], ['target_directory_id' => $trashed->id]);

    foreach ([$gone, $inTrash] as $import) {
        expect($import->status)->toBe(FileImportStatus::Failed);
        expect($import->error)->toBe('La carpeta destino ya no existe o está en la papelera.');
    }
    expect(File::count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('claims the import atomically: a worker that lost the race does nothing', function () {
    $this->drive->file('a', 'a.txt', 'x');
    $import = FileImport::create(['kind' => FileImportKind::Import, 'provider' => 'google_drive', 'source_items' => ['a']]);

    // Otro worker lo toma justo antes de que este escriba su claim.
    $raced = false;
    \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $sql) use (&$raced, $import) {
        if (! $raced && str_starts_with(strtolower($sql), 'update') && str_contains($sql, 'file_imports')) {
            $raced = true;
            \Illuminate\Support\Facades\DB::table('file_imports')->where('id', $import->id)->update(['status' => 'running', 'trace_id' => 'otro']);
        }
    });

    $result = (new ImportFromDrive($import->id))->execute();

    expect(File::count())->toBe(0);
    expect($result->status)->toBe(FileImportStatus::Running);
    expect($import->fresh()->trace_id)->toBe('otro');
});

it('records a folder that cannot be created as failed and skips everything under it', function () {
    config()->set('innertia.directories.max_depth', 2);
    $this->drive->folder('A', 'A');
    $this->drive->file('a1', 'a.txt', 'x', 'A');
    $this->drive->folder('B', 'B', 'A');
    $this->drive->file('b1', 'b.txt', 'x', 'B');
    $this->drive->folder('C', 'C', 'B');
    $this->drive->file('c1', 'c.txt', 'x', 'C');
    $this->drive->folder('D', 'D', 'C');
    $this->drive->file('d1', 'd.txt', 'x', 'D');
    $this->drive->file('loose', 'loose.txt', 'x');

    $import = runImport(['A', 'loose']);

    expect($import->status)->toBe(FileImportStatus::Done);
    expect([$import->imported, $import->skipped, $import->failed_count])->toBe([3, 3, 1]);
    $items = collect($import->items)->keyBy('external_id');
    expect($items['C']['result'])->toBe('failed');
    expect($items['C']['is_folder'])->toBeTrue();
    expect($items['D']['result'])->toBe('skipped');
    expect($items['D']['is_folder'])->toBeTrue();
    expect($items['c1']['result'])->toBe('skipped');
    expect($items['c1']['reason'])->toBe('No se pudo crear la carpeta contenedora.');
    expect($items['d1']['result'])->toBe('skipped');
    expect(File::pluck('original_name')->all())->toEqualCanonicalizing(['a.txt', 'b.txt', 'loose.txt']);
});

it('skips an item another process is importing (lock contention)', function () {
    config()->set('innertia.files.drive.lock_wait', 0);
    $this->drive->file('a', 'a.txt', 'x');
    $this->drive->file('b', 'b.txt', 'y');
    $owner = \Innertia\Files\Drive\Import\ImportOwner::from([]);
    $lock = \Illuminate\Support\Facades\Cache::lock(\Innertia\Files\Drive\Import\DriveFileImporter::lockKey('a', null, $owner), 60);
    expect($lock->get())->toBeTrue();

    $import = runImport(['a', 'b']);
    $lock->release();

    expect([$import->imported, $import->skipped, $import->failed_count])->toBe([1, 1, 0]);
    expect(collect($import->items)->firstWhere('external_id', 'a')['reason'])->toBe('Otro proceso está importando este elemento.');
});

it('stops the whole import on a Drive auth or connection error, keeping partial results', function (string $reason) {
    $this->drive->file('ok', 'ok.txt', 'x');
    $this->drive->file('bad', 'bad.txt', 'y');
    $this->drive->file('next', 'next.txt', 'z');
    $this->drive->failures['bad'] = new \Innertia\Files\Drive\Exceptions\DriveException('Google caído', $reason);

    $import = FileImport::create(['kind' => FileImportKind::Import, 'provider' => 'google_drive', 'source_items' => ['ok', 'bad', 'next']]);
    expect(fn () => (new ImportFromDrive($import->id))->execute())->toThrow(\Innertia\Files\Drive\Exceptions\DriveException::class);

    $import = $import->fresh();
    expect($import->status)->toBe(FileImportStatus::Failed);
    expect($import->error)->toBe('Google caído');
    expect($import->imported)->toBe(1);
    expect($this->drive->calls)->not->toContain('download:next');
})->with(['auth', 'connection']);

it('marks a still-running import as failed when the job dies', function () {
    $running = FileImport::create(['kind' => FileImportKind::Import, 'provider' => 'google_drive', 'source_items' => ['a']]);
    $running->markRunning('t');
    $done = FileImport::create(['kind' => FileImportKind::Import, 'provider' => 'google_drive', 'source_items' => ['a']]);
    $done->markDone();

    (new ImportFromDrive($running->id))->failed(new \RuntimeException('timeout'));
    (new ImportFromDrive($done->id))->failed(new \RuntimeException('timeout'));

    expect($running->fresh()->status)->toBe(FileImportStatus::Failed);
    expect($running->fresh()->error)->toBe('El proceso se interrumpió: ' . \Innertia\Files\Drive\Import\ImportErrors::GENERIC);
    expect($done->fresh()->status)->toBe(FileImportStatus::Done);
});

it('fails the import without throwing when folders are planned but directories lacks the provenance columns', function () {
    \Illuminate\Support\Facades\Schema::table('directories', fn ($t) => $t->dropColumn(['provider', 'external_id']));
    \Innertia\Files\Drive\Import\DriveDirectoryMapper::flushSchemaCache();
    $this->drive->folder('A', 'A');
    $this->drive->file('a1', 'a.txt', 'x', 'A');

    $import = runImport(['A']);

    expect($import->status)->toBe(FileImportStatus::Failed);
    expect($import->error)->toBe('Faltan las columnas de procedencia en directories: agrega provider y external_id a tu migración de directories (ver innertia:directories:install).');
    expect(File::count())->toBe(0);
    expect(Directory::count())->toBe(0);
});

it('never exposes internal errors in item reasons (generic text, full detail only in the log)', function () {
    \Illuminate\Support\Facades\Schema::table('files', fn ($t) => $t->dropColumn('external_url'));
    $this->drive->file('a', 'a.txt', 'x');

    $import = runImport(['a']);

    $reason = collect($import->items)->firstWhere('external_id', 'a')['reason'];
    expect($import->failed_count)->toBe(1);
    expect($reason)->toBe('Error interno al importar el elemento (ver registro con el trace_id).');
    expect($reason)->not->toContain('SQLSTATE');
});

it('marks the import failed when the job dies without an exception', function () {
    $running = FileImport::create(['kind' => FileImportKind::Import, 'provider' => 'google_drive', 'source_items' => ['a']]);
    $running->markRunning('t');

    (new ImportFromDrive($running->id))->failed(null);

    expect($running->fresh()->error)->toBe('El proceso se interrumpió: sin detalle');
});

it('files inherit the folder owner when the import has no owner attributes', function () {
    $ownerId = '33333333-3333-3333-3333-333333333333';
    $target = (new \Innertia\Files\Directories\UseCases\CreateDirectory(null, 'Destino'))->execute();
    $target->forceFill(['owner_type' => 'team', 'owner_id' => $ownerId])->save();
    $this->drive->folder('A', 'A');
    $this->drive->file('a1', 'a.txt', 'x', 'A');
    $this->drive->file('loose', 'b.txt', 'y');

    runImport(['A', 'loose'], ['target_directory_id' => $target->id]);
    $again = runImport(['A', 'loose'], ['target_directory_id' => $target->id]);

    expect(File::count())->toBe(2);
    foreach (File::all() as $file) {
        expect([$file->owner_type, $file->owner_id])->toBe(['team', $ownerId]);
    }
    expect([$again->imported, $again->unchanged])->toBe([0, 2]);
});
