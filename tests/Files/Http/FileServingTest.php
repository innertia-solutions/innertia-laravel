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

function _servingTyped(string $mime, string $ext, string $visibility = 'auth'): File
{
    $path = "docs/file.$ext";
    Storage::disk('local')->put($path, 'DATA');

    return File::create([
        'disk'          => 'local',
        'path'          => $path,
        'original_name' => "file.$ext",
        'mime_type'     => $mime,
        'extension'     => $ext,
        'size'          => 4,
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
    $url  = url(URL::temporarySignedRoute('innertia.files.view', now()->subMinute(), ['id' => $file->id], absolute: false));

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

// ── Content-Disposition inteligente + serving seguro ────────────────────────────

it('sirve un PDF inline (view)', function () {
    $file = _servingTyped('application/pdf', 'pdf');

    $res = test()->get($file->signedViewUrl())->assertOk();

    expect($res->headers->get('content-disposition'))->toStartWith('inline');
});

it('sirve una imagen inline (view)', function () {
    $file = _servingTyped('image/png', 'png');

    $res = test()->get($file->signedViewUrl())->assertOk();

    expect($res->headers->get('content-disposition'))->toStartWith('inline');
});

it('fuerza descarga de un HTML aunque sea view (anti-XSS)', function () {
    $file = _servingTyped('text/html', 'html');

    $res = test()->get($file->signedViewUrl())->assertOk();

    expect($res->headers->get('content-disposition'))->toStartWith('attachment');
});

it('fuerza descarga de un SVG aunque sea view (anti-XSS)', function () {
    $file = _servingTyped('image/svg+xml', 'svg');

    $res = test()->get($file->signedViewUrl())->assertOk();

    expect($res->headers->get('content-disposition'))->toStartWith('attachment');
});

it('el download siempre fuerza attachment, incluso para PDF', function () {
    $file = _servingTyped('application/pdf', 'pdf');

    $res = test()->get($file->signedDownloadUrl())->assertOk();

    expect($res->headers->get('content-disposition'))->toStartWith('attachment');
});

it('agrega X-Content-Type-Options: nosniff', function () {
    $file = _servingTyped('application/pdf', 'pdf');

    test()->get($file->signedViewUrl())
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('un archivo público sirve con cache-control cacheable', function () {
    $file = _servingTyped('image/png', 'png', 'public');

    $res = test()->get('/files/' . $file->id . '/view')->assertOk();

    expect($res->headers->get('cache-control'))->toContain('public');
});

it('un archivo privado sirve con cache-control no-store', function () {
    $file = _servingTyped('application/pdf', 'pdf', 'auth');

    $res = test()->get($file->signedViewUrl())->assertOk();

    expect($res->headers->get('cache-control'))->toContain('no-store');
});

it('FileResource de un archivo público expone URL estable SIN firma', function () {
    $file = _servingTyped('image/png', 'png', 'public');

    $data = (new FileResource($file))->toArray(request());

    expect($data['view_url'])->not->toContain('signature=');
    expect($data['view_url'])->toContain('/files/' . $file->id . '/view');
    expect($data['download_url'])->not->toContain('signature=');
});

it('un id malformado (no-uuid) devuelve 404, no un error de SQL', function () {
    test()->get('/files/not-a-valid-uuid/view')->assertStatus(404);
});
