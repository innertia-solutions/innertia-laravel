<?php
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Directories\UseCases\CreateDirectory;
use Innertia\Files\Drive\DriveFileFetcher;
use Innertia\Files\Drive\Import\DriveDirectoryMapper;
use Innertia\Files\Drive\Import\DriveFileImporter;
use Innertia\Files\Drive\Import\DriveFileRefresher;
use Innertia\Files\Imports\Enums\FileImportKind;
use Innertia\Files\Imports\Models\FileImport;
use Innertia\Files\Models\File;

beforeEach(function () {
    require_once __DIR__ . '/helpers.php';
    $this->drive = driveTestBoot();
    $this->import = FileImport::create([
        'kind' => FileImportKind::Import, 'provider' => 'google_drive', 'source_items' => [],
        'file_attributes' => ['owner_type' => 'platform', 'owner_id' => null], 'created_by' => 'u-1',
    ]);
    $fetcher = new DriveFileFetcher($this->drive);
    $this->mapper = new DriveDirectoryMapper();
    $this->importer = new DriveFileImporter($fetcher, new DriveFileRefresher($fetcher));
});

afterEach(fn () => driveTestShutdown());

it('creates a directory linked to the Drive folder with the import owner', function () {
    $folder = $this->drive->folder('A', 'Auditoría');

    $dir = $this->mapper->ensure($folder, null, $this->import);

    expect($dir->name)->toBe('Auditoría');
    expect($dir->provider)->toBe('google_drive');
    expect($dir->external_id)->toBe('A');
    expect($dir->owner_type)->toBe('platform');
    expect($dir->created_by)->toBe('u-1');
});

it('reuses the same linked directory on re-import', function () {
    $folder = $this->drive->folder('A', 'Auditoría');

    $first = $this->mapper->ensure($folder, null, $this->import);
    $second = $this->mapper->ensure($folder, null, $this->import);

    expect($second->id)->toBe($first->id);
    expect(Directory::count())->toBe(1);
});

it('suffixes the name when an unrelated sibling already uses it and sanitizes separators', function () {
    (new CreateDirectory(null, 'Auditoría'))->execute();

    $dir = $this->mapper->ensure($this->drive->folder('A', 'Auditoría'), null, $this->import);
    $slash = $this->mapper->ensure($this->drive->folder('S', 'Q1/Q2'), null, $this->import);

    expect($dir->name)->toBe('Auditoría (2)');
    expect($slash->name)->toBe('Q1-Q2');
});

it('nests child directories under their parent', function () {
    $parent = $this->mapper->ensure($this->drive->folder('A', 'A'), null, $this->import);
    $child = $this->mapper->ensure($this->drive->folder('B', 'B', 'A'), $parent, $this->import);

    expect($child->parent_id)->toBe($parent->id);
    expect($child->owner_type)->toBe('platform');
});

it('imports a new file with provenance, checksum and import attributes', function () {
    $item = $this->drive->file('f1', 'acta.pdf', '%PDF-1', mime: 'application/pdf');
    $dir = (new CreateDirectory(null, 'Destino'))->execute();

    [$result, $fileId] = $this->importer->import($item, $dir, $this->import);

    expect($result)->toBe('imported');
    $file = File::find($fileId);
    expect($file->original_name)->toBe('acta.pdf');
    expect($file->directory_id)->toBe($dir->id);
    expect($file->provider)->toBe('google_drive');
    expect($file->link_mode)->toBe('local');
    expect($file->external_id)->toBe('f1');
    expect($file->external_url)->toBe('https://drive/f1');
    expect($file->external_revision)->toBe(md5('%PDF-1'));
    expect($file->external_synced_at)->not->toBeNull();
    expect($file->version_source)->toBe('provider_import');
    expect($file->checksum)->toBe(hash('sha256', '%PDF-1'));
    expect($file->owner_type)->toBe('platform');
    expect($file->created_by)->toBe('u-1');
    expect($file->visibility)->toBe('auth');
    expect(Storage::disk('local')->get($file->path))->toBe('%PDF-1');
});

it('refreshes instead of duplicating when the same Drive file is already in that directory', function () {
    $item = $this->drive->file('f1', 'acta.txt', 'uno');
    [, $id] = $this->importer->import($item, null, $this->import);

    $this->drive->edit('f1', 'dos');
    [$result, $sameId] = $this->importer->import($this->drive->metadata('f1'), null, $this->import);
    [$again] = $this->importer->import($this->drive->metadata('f1'), null, $this->import);

    expect($result)->toBe('updated');
    expect($sameId)->toBe($id);
    expect($again)->toBe('unchanged');
    expect(File::count())->toBe(1);
    expect(File::find($id)->version)->toBe(2);
});

it('treats the same Drive file in another directory as a separate import', function () {
    $item = $this->drive->file('f1', 'acta.txt', 'uno');
    $other = (new CreateDirectory(null, 'Otra'))->execute();

    $this->importer->import($item, null, $this->import);
    [$result] = $this->importer->import($item, $other, $this->import);

    expect($result)->toBe('imported');
    expect(File::count())->toBe(2);
});

it('cleans the stored bytes when creating the File row fails', function () {
    $item = $this->drive->file('f1', 'acta.txt', 'uno');
    \Illuminate\Support\Facades\Schema::table('files', fn ($t) => $t->dropColumn('external_url'));

    expect(fn () => $this->importer->import($item, null, $this->import))->toThrow(\Illuminate\Database\QueryException::class);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});
