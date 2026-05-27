<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Testing\File as TestFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Directories\Models\Directory;
use Innertia\Files\Models\File;

beforeEach(function () {
    config()->set('innertia.directories.enabled', true);
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');

    require_once __DIR__ . '/../helpers/migrate.php';
    require_once __DIR__ . '/../../Files/Directories/helpers/migrate.php';
    innertiaFilesMigrateUp();
    innertiaDirectoriesMigrateUp();

    Schema::table('files', function (Blueprint $table) {
        if (! Schema::hasColumn('files', 'directory_id')) {
            $table->uuid('directory_id')->nullable()->index()->after('owner_id');
        }
    });

    Storage::fake('local');

    Route::middleware([])->group(function () {
        \Innertia\Files\Routes::register();
        \Innertia\Files\Directories\Routes::register();
    });
});

afterEach(function () {
    innertiaDirectoriesMigrateDown();
    innertiaFilesMigrateDown();
});

it('GET /directories/{id}/files returns files in the directory', function () {
    $dir = Directory::createIn(null, 'Reports');
    File::create([
        'disk' => 'local', 'path' => 'a.txt', 'original_name' => 'a.txt', 'directory_id' => $dir->id,
    ]);
    File::create([
        'disk' => 'local', 'path' => 'b.txt', 'original_name' => 'b.txt', 'directory_id' => $dir->id,
    ]);
    File::create([
        'disk' => 'local', 'path' => 'orphan.txt', 'original_name' => 'orphan.txt',
    ]);

    $response = $this->getJson("/directories/{$dir->id}/files");

    $response->assertOk()->assertJsonCount(2, 'data');
});

it('GET /directories/{id}/files filters by search', function () {
    $dir = Directory::createIn(null, 'Reports');
    File::create([
        'disk' => 'local', 'path' => 'invoice-jan.pdf', 'original_name' => 'invoice-jan.pdf', 'directory_id' => $dir->id,
    ]);
    File::create([
        'disk' => 'local', 'path' => 'report.pdf', 'original_name' => 'report.pdf', 'directory_id' => $dir->id,
    ]);

    $response = $this->getJson("/directories/{$dir->id}/files?search=invoice");

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('returns 404 when directory does not exist', function () {
    $this->getJson('/directories/00000000-0000-0000-0000-000000000000/files')
        ->assertNotFound();
});

it('POST /files with directory_id stores file in the directory', function () {
    $dir = Directory::createIn(null, 'Reports');

    $response = $this->postJson('/files', [
        'file'         => TestFile::create('report.pdf', 100, 'application/pdf'),
        'directory_id' => $dir->id,
    ]);

    $response->assertCreated();
    $fileId = $response->json('data.id');

    $created = File::find($fileId);
    expect($created->directory_id)->toBe($dir->id);
});

it('PATCH /files/{id} moves file by setting directory_id', function () {
    $sourceDir = Directory::createIn(null, 'Source');
    $targetDir = Directory::createIn(null, 'Target');

    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
        'directory_id' => $sourceDir->id,
    ]);

    $this->patchJson("/files/{$file->id}", ['directory_id' => $targetDir->id])
        ->assertOk();

    expect(File::find($file->id)->directory_id)->toBe($targetDir->id);
});

it('PATCH /files/{id} with directory_id=null moves to root', function () {
    $dir = Directory::createIn(null, 'Reports');
    $file = File::create([
        'disk' => 'local', 'path' => 'foo.txt', 'original_name' => 'foo.txt',
        'directory_id' => $dir->id,
    ]);

    $this->patchJson("/files/{$file->id}", ['directory_id' => null])
        ->assertOk();

    expect(File::find($file->id)->directory_id)->toBeNull();
});
