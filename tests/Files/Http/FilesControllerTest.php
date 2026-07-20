<?php

use Illuminate\Http\Testing\File as TestFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Innertia\Files\Models\File;
use Innertia\Files\UseCases\UploadFile;

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaFilesMigrateUp();

    Storage::fake('local');

    // Note: we only register the CRUD routes. The file-serving routes
    // (innertia.files.view / innertia.files.download) would conflict with
    // GET /files/{id} (show endpoint) and are auto-registered by the SP
    // in production. FileResource returns null for view_url/download_url
    // when those routes are absent (see tryFileUrl()).
    Route::middleware([])->group(function () {
        \Innertia\Files\Routes::register();
    });
    // Serving routes (innertia.files.view/download) — necesarias para que
    // signedViewUrl()/share-link/view_url firmado resuelvan la ruta.
    \Innertia\Files\Routes::registerFileServing();
});

afterEach(fn () => innertiaFilesMigrateDown());

// ── index ─────────────────────────────────────────────────────────────────────

it('lists files with pagination', function () {
    (new UploadFile(TestFile::create('a.pdf', 100, 'application/pdf')))->execute();
    (new UploadFile(TestFile::create('b.pdf', 100, 'application/pdf')))->execute();

    $response = $this->getJson('/files');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data', 'meta', 'links']);
});

it('filters by search on original_name', function () {
    (new UploadFile(TestFile::create('report.pdf', 100, 'application/pdf')))->execute();
    (new UploadFile(TestFile::create('invoice.pdf', 100, 'application/pdf')))->execute();

    $response = $this->getJson('/files?search=report');

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.original_name'))->toBe('report.pdf');
});

it('returns trashed only with ?trashed=only', function () {
    $fileA = (new UploadFile(TestFile::create('active.pdf', 100, 'application/pdf')))->execute();
    $fileB = (new UploadFile(TestFile::create('trashed.pdf', 100, 'application/pdf')))->execute();
    $fileB->trash();

    $response = $this->getJson('/files?trashed=only');

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.original_name'))->toBe('trashed.pdf');
});

it('returns all files including trashed with ?trashed=with', function () {
    $fileA = (new UploadFile(TestFile::create('active.pdf', 100, 'application/pdf')))->execute();
    $fileB = (new UploadFile(TestFile::create('trashed.pdf', 100, 'application/pdf')))->execute();
    $fileB->trash();

    $response = $this->getJson('/files?trashed=with');

    $response->assertOk()->assertJsonCount(2, 'data');
});

// ── show ──────────────────────────────────────────────────────────────────────

it('shows a single file', function () {
    $file = (new UploadFile(TestFile::create('doc.pdf', 200, 'application/pdf')))->execute();

    $response = $this->getJson("/files/{$file->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $file->id)
        ->assertJsonPath('data.original_name', 'doc.pdf')
        ->assertJsonStructure(['data' => ['id', 'original_name', 'mime_type', 'size', 'view_url', 'download_url']]);
});

it('returns 404 on missing file', function () {
    $this->getJson('/files/00000000-0000-0000-0000-000000000000')
        ->assertNotFound();
});

// ── store ─────────────────────────────────────────────────────────────────────

it('uploads a file via POST', function () {
    $response = $this->postJson('/files', [
        'file' => TestFile::create('report.pdf', 100, 'application/pdf'),
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.original_name', 'report.pdf')
        ->assertJsonPath('data.visibility', 'auth');

    expect(File::where('original_name', 'report.pdf')->exists())->toBeTrue();
});

it('uploads a file with custom visibility', function () {
    $response = $this->postJson('/files', [
        'file'       => TestFile::create('public.pdf', 50, 'application/pdf'),
        'visibility' => 'public',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.visibility', 'public');
});

it('rejects upload without file field', function () {
    $this->postJson('/files', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

it('rejects upload with invalid visibility', function () {
    $this->postJson('/files', [
        'file'       => TestFile::create('x.pdf', 50, 'application/pdf'),
        'visibility' => 'unknown',
    ])->assertStatus(422)
      ->assertJsonValidationErrors(['visibility']);
});

// ── update ────────────────────────────────────────────────────────────────────

it('renames a file via PATCH', function () {
    $file = (new UploadFile(TestFile::create('old.pdf', 100, 'application/pdf')))->execute();

    $this->patchJson("/files/{$file->id}", ['original_name' => 'new-name.pdf'])
        ->assertOk()
        ->assertJsonPath('data.original_name', 'new-name.pdf');

    expect(File::find($file->id)->original_name)->toBe('new-name.pdf');
});

it('rejects invalid name on PATCH (empty string)', function () {
    $file = (new UploadFile(TestFile::create('test.pdf', 100, 'application/pdf')))->execute();

    $this->patchJson("/files/{$file->id}", ['original_name' => '   '])
        ->assertStatus(422);
});

it('rejects name with path separator on PATCH', function () {
    $file = (new UploadFile(TestFile::create('test.pdf', 100, 'application/pdf')))->execute();

    $this->patchJson("/files/{$file->id}", ['original_name' => 'bad/name.pdf'])
        ->assertStatus(422);
});

it('cambia la visibilidad a public via PATCH', function () {
    $file = (new UploadFile(TestFile::create('doc.pdf', 100, 'application/pdf')))->execute();

    $this->patchJson("/files/{$file->id}", ['visibility' => 'public'])
        ->assertOk()
        ->assertJsonPath('data.visibility', 'public');

    expect(File::find($file->id)->visibility)->toBe('public');
});

it('rechaza una visibilidad inválida via PATCH', function () {
    $file = (new UploadFile(TestFile::create('doc.pdf', 100, 'application/pdf')))->execute();

    $this->patchJson("/files/{$file->id}", ['visibility' => 'nope'])
        ->assertStatus(422);
});

// ── destroy ───────────────────────────────────────────────────────────────────

it('soft deletes a file via DELETE', function () {
    $file = (new UploadFile(TestFile::create('todelete.pdf', 100, 'application/pdf')))->execute();

    $this->deleteJson("/files/{$file->id}")
        ->assertNoContent();

    expect(File::find($file->id))->toBeNull();
    expect(File::withTrashed()->find($file->id))->not->toBeNull();
});

it('hard deletes with ?force=true', function () {
    $file = (new UploadFile(TestFile::create('harddelete.pdf', 100, 'application/pdf')))->execute();

    $this->deleteJson("/files/{$file->id}?force=true")
        ->assertNoContent();

    expect(File::withTrashed()->find($file->id))->toBeNull();
});

// ── restore ───────────────────────────────────────────────────────────────────

it('restores a trashed file via POST', function () {
    $file = (new UploadFile(TestFile::create('toRestore.pdf', 100, 'application/pdf')))->execute();
    $file->trash();

    $this->postJson("/files/{$file->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.original_name', 'toRestore.pdf');

    expect(File::find($file->id))->not->toBeNull();
});

it('returns 404 when restoring non-trashed file', function () {
    $file = (new UploadFile(TestFile::create('active.pdf', 100, 'application/pdf')))->execute();

    $this->postJson("/files/{$file->id}/restore")
        ->assertNotFound();
});

// ── trash ─────────────────────────────────────────────────────────────────────

it('lists trash via GET /files/trash', function () {
    $fileA = (new UploadFile(TestFile::create('active.pdf', 100, 'application/pdf')))->execute();
    $fileB = (new UploadFile(TestFile::create('trashed.pdf', 100, 'application/pdf')))->execute();
    $fileB->trash();

    $response = $this->getJson('/files/trash');

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.original_name'))->toBe('trashed.pdf');
});

// ── empty trash ───────────────────────────────────────────────────────────────

it('empties trash via POST /files/trash/empty', function () {
    $fileA = (new UploadFile(TestFile::create('a.pdf', 100, 'application/pdf')))->execute();
    $fileB = (new UploadFile(TestFile::create('b.pdf', 100, 'application/pdf')))->execute();
    $fileA->trash();
    $fileB->trash();

    $this->postJson('/files/trash/empty')
        ->assertOk()
        ->assertJsonPath('deleted', 2);

    expect(File::withTrashed()->count())->toBe(0);
});

// ── share-link (URL firmada con TTL en horas) ───────────────────────────────────

it('genera un share-link firmado con TTL en horas', function () {
    $file = (new UploadFile(TestFile::create('doc.pdf', 100, 'application/pdf')))->execute();

    $res = $this->getJson("/files/{$file->id}/share-link?hours=48")
        ->assertOk()
        ->assertJsonPath('expires_in_hours', 48);

    expect($res->json('url'))->toContain('signature=');
    expect($res->json('url'))->toContain("/files/{$file->id}/view");
});

it('el share-link con download=1 apunta a la ruta de descarga', function () {
    $file = (new UploadFile(TestFile::create('doc.pdf', 100, 'application/pdf')))->execute();

    $res = $this->getJson("/files/{$file->id}/share-link?hours=2&download=1")->assertOk();

    expect($res->json('url'))->toContain("/files/{$file->id}/download");
});

it('el share-link usa 24h por defecto', function () {
    $file = (new UploadFile(TestFile::create('doc.pdf', 100, 'application/pdf')))->execute();

    $this->getJson("/files/{$file->id}/share-link")
        ->assertOk()
        ->assertJsonPath('expires_in_hours', 24);
});

it('el share-link rechaza hours fuera de rango', function () {
    $file = (new UploadFile(TestFile::create('doc.pdf', 100, 'application/pdf')))->execute();

    $this->getJson("/files/{$file->id}/share-link?hours=0")->assertStatus(422);
});
