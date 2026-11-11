<?php

use Illuminate\Support\Facades\Route;
use Innertia\Files\Drive\DriveSource;
use Innertia\Files\Drive\Exceptions\DriveException;

beforeEach(function () {
    require_once __DIR__ . '/helpers.php';
    $this->drive = driveTestBoot();
    Route::middleware([])->group(fn () => \Innertia\Files\Routes::registerDriveImport('api'));
});

afterEach(fn () => driveTestShutdown());

it('browses the root with shared drives and a folder without them', function () {
    $this->drive->folder('A', 'Auditoría');
    $this->drive->file('f', 'a.txt', 'x');
    $this->drive->file('a1', 'b.txt', 'y', 'A');

    $root = test()->getJson('/api/drive/browse')->assertOk();
    expect(collect($root->json('items'))->pluck('id')->all())->toBe(['A', 'f']);
    expect($root->json('shared_drives'))->toBe([]);

    $inner = test()->getJson('/api/drive/browse?parent=A')->assertOk();
    expect(collect($inner->json('items'))->pluck('id')->all())->toBe(['a1']);
    expect($inner->json('shared_drives'))->toBeNull();
});

it('filters folders only', function () {
    $this->drive->folder('A', 'A');
    $this->drive->file('f', 'a.txt', 'x');

    $res = test()->getJson('/api/drive/browse?folders_only=1')->assertOk();

    expect(collect($res->json('items'))->pluck('id')->all())->toBe(['A']);
});

it('returns an item with its path, and 404 when it does not exist', function () {
    $this->drive->folder('A', 'A');

    test()->getJson('/api/drive/items/A')->assertOk()
        ->assertJsonPath('item.id', 'A')
        ->assertJsonPath('path.0.name', 'Mi unidad');
    test()->getJson('/api/drive/items/nope')->assertNotFound();
});

it('creates a folder', function () {
    test()->postJson('/api/drive/folders', ['name' => 'Nueva', 'parent' => 'root'])
        ->assertCreated()
        ->assertJsonPath('item.name', 'Nueva');
    test()->postJson('/api/drive/folders', ['name' => ''])->assertStatus(422);
});

it('rejects malformed ids', function () {
    test()->getJson("/api/drive/browse?parent=a'b")->assertStatus(422);
});

it('answers 422 when Drive is not configured and 422 with the Google message on API errors', function () {
    app()->offsetUnset(DriveSource::class);
    test()->getJson('/api/drive/browse')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'no está configurado'));

    $broken = Mockery::mock(DriveSource::class);
    $broken->shouldReceive('listChildren')->andThrow(new DriveException('Google Drive no permitió listar la carpeta: API disabled'));
    $broken->shouldReceive('sharedDrives')->andReturn([]);
    app()->instance(DriveSource::class, $broken);
    test()->getJson('/api/drive/browse')->assertStatus(422)->assertJsonPath('message', 'Google Drive no permitió listar la carpeta: API disabled');
});
