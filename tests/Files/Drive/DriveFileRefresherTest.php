<?php

use Illuminate\Support\Facades\Storage;
use Innertia\Files\Drive\DriveFileFetcher;
use Innertia\Files\Drive\Import\DriveFileRefresher;
use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;

beforeEach(function () {
    require_once __DIR__ . '/helpers.php';
    $this->drive = driveTestBoot();
    $this->refresher = new DriveFileRefresher(new DriveFileFetcher($this->drive));
});

afterEach(fn () => driveTestShutdown());

/** File local "importado" desde un ítem del fake. */
function importedFile(string $externalId, string $bytes, ?string $revision, ?string $exportMime = null, string $name = 'doc.txt'): File
{
    Storage::disk('local')->put("files/{$externalId}.bin", $bytes);

    return File::create([
        'disk' => 'local', 'path' => "files/{$externalId}.bin", 'original_name' => $name,
        'mime_type' => 'text/plain', 'extension' => pathinfo($name, PATHINFO_EXTENSION) ?: null, 'size' => strlen($bytes),
        'provider' => 'google_drive', 'link_mode' => 'local', 'external_id' => $externalId,
        'external_revision' => $revision, 'external_export_mime' => $exportMime,
    ]);
}

it('is unchanged without downloading when the revision is the same, and touches synced_at', function () {
    $item = $this->drive->file('f1', 'doc.txt', 'uno');
    $file = importedFile('f1', 'uno', $item->revision());

    expect($this->refresher->refresh($file, $item))->toBe('unchanged');
    expect(in_array('download:f1', $this->drive->calls, true))->toBeFalse();
    expect($file->fresh()->external_synced_at)->not->toBeNull();
    expect($file->fresh()->version)->toBe(1);
});

it('creates a new version when Drive changed, keeping the local name', function () {
    $this->drive->file('f1', 'doc.txt', 'uno');
    $file = importedFile('f1', 'uno', md5('uno'), name: 'mi-nombre.txt');
    $this->drive->edit('f1', 'dos');

    $result = $this->refresher->refresh($file->fresh(), $this->drive->metadata('f1'));

    expect($result)->toBe('updated');
    $fresh = $file->fresh();
    expect($fresh->version)->toBe(2);
    expect($fresh->version_source)->toBe('provider_refresh');
    expect($fresh->external_revision)->toBe(md5('dos'));
    expect($fresh->original_name)->toBe('mi-nombre.txt');
    expect(Storage::disk('local')->get($fresh->path))->toBe('dos');
    expect(FileVersion::where('file_id', $file->id)->count())->toBe(1);
});

it('is unchanged when the revision moved but the bytes are identical (and stores the new revision)', function () {
    $this->drive->file('f1', 'doc.txt', 'uno');
    $file = importedFile('f1', 'uno', 'vieja');

    expect($this->refresher->refresh($file, $this->drive->metadata('f1')))->toBe('unchanged');
    expect($file->fresh()->external_revision)->toBe(md5('uno'));
    expect($file->fresh()->version)->toBe(1);
});

it('refreshes natives in the same export format', function () {
    $this->drive->native('d1', 'Acta', 'document', [DOCX => 'v1-docx', 'application/pdf' => 'v1-pdf']);
    $file = importedFile('d1', 'v1-pdf', '1', 'application/pdf', 'Acta.pdf');
    $this->drive->edit('d1', 'v2');

    expect($this->refresher->refresh($file, $this->drive->metadata('d1')))->toBe('updated');
    expect(in_array('export:d1:application/pdf', $this->drive->calls, true))->toBeTrue();
    expect($file->fresh()->external_export_mime)->toBe('application/pdf');
});

it('leaves no temp files behind', function () {
    $this->drive->file('f1', 'doc.txt', 'uno');
    $file = importedFile('f1', 'uno', 'x');
    $before = glob(sys_get_temp_dir() . '/innertia-*') ?: [];

    $this->refresher->refresh($file, $this->drive->metadata('f1'));

    expect(glob(sys_get_temp_dir() . '/innertia-*') ?: [])->toBe($before);
});
