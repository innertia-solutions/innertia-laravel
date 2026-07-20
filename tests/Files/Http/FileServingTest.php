<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Innertia\Files\Http\Resources\FileResource;
use Innertia\Files\Models\File;
use Innertia\Files\Routes as FilesRoutes;

beforeEach(function () {
    config()->set('innertia.mode', 'app');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaFilesMigrateUp();

    Storage::fake('local');
    FilesRoutes::registerFileServing();
});

afterEach(fn () => innertiaFilesMigrateDown());

function _servingFile(string $visibility = 'auth'): File
{
    Storage::disk('local')->put('docs/report.pdf', 'PDFDATA');

    return File::create([
        'disk'          => 'local',
        'path'          => 'docs/report.pdf',
        'original_name' => 'report.pdf',
        'mime_type'     => 'application/pdf',
        'extension'     => 'pdf',
        'size'          => 7,
        'visibility'    => $visibility,
    ]);
}

it('serves an auth file via a valid signed URL without an authenticated user', function () {
    $file = _servingFile('auth');

    test()->get($file->signedViewUrl())->assertOk();
});

it('serves a restricted file via a valid signed URL — the signature is the credential', function () {
    $file = _servingFile('restricted');

    test()->get($file->signedViewUrl())->assertOk();
});

it('rejects an expired signed URL with 403', function () {
    $file = _servingFile('auth');
    $url  = URL::temporarySignedRoute('innertia.files.view', now()->subMinute(), ['id' => $file->id]);

    test()->get($url)->assertStatus(403);
});

it('rejects a tampered signature with 403', function () {
    $file = _servingFile('auth');

    test()->get($file->signedViewUrl() . 'tampered')->assertStatus(403);
});

it('still requires auth when no signature is present (401)', function () {
    $file = _servingFile('auth');

    test()->get('/files/' . $file->id . '/view')->assertStatus(401);
});

it('serves a public file without signature or auth', function () {
    $file = _servingFile('public');

    test()->get('/files/' . $file->id . '/view')->assertOk();
});

it('FileResource exposes signed view_url and download_url', function () {
    $file = _servingFile('auth');

    $data = (new FileResource($file))->toArray(request());

    expect($data['view_url'])->toContain('signature=');
    expect($data['download_url'])->toContain('signature=');
});
