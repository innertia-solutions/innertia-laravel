<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Innertia\Facades\Settings;
use Innertia\Files\Drive\DriveSource;
use Innertia\Files\Drive\DriveSourceResolver;
use Innertia\Files\Drive\Exceptions\DriveException;
use Innertia\Files\Drive\Exceptions\DriveExportTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveFileTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveItemNotFoundException;
use Innertia\Files\Drive\Exceptions\DriveItemNotImportableException;
use Innertia\Files\Drive\Exceptions\DriveNotConfiguredException;
use Innertia\Files\Drive\ServiceAccountDriveSource;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    seedServiceAccount();
});

const API = 'www.googleapis.com/drive/v3';

function tokenFake(): array
{
    return ['oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600])];
}

it('lists children across pages with the right query', function () {
    Http::fake(tokenFake() + [
        API . '/files?*' => Http::sequence()
            ->push(['files' => [['id' => 'a', 'name' => 'A', 'mimeType' => 'application/pdf']], 'nextPageToken' => 'p2'])
            ->push(['files' => [['id' => 'b', 'name' => 'B', 'mimeType' => 'application/vnd.google-apps.folder']]]),
    ]);

    $items = app(ServiceAccountDriveSource::class)->listChildren("ro'ot", 'shared-1');

    expect(array_map(fn ($i) => $i->id, $items))->toBe(['a', 'b']);
    Http::assertSent(function ($req) {
        if (! str_contains($req->url(), API . '/files?')) {
            return false;
        }
        parse_str(parse_url($req->url(), PHP_URL_QUERY), $q);

        return $q['q'] === "'ro\\'ot' in parents and trashed=false"
            && $q['corpora'] === 'drive' && $q['driveId'] === 'shared-1'
            && $q['supportsAllDrives'] === 'true' && $req->hasHeader('Authorization', 'Bearer tok-1');
    });
});

it('filters folders only', function () {
    Http::fake(tokenFake() + [API . '/files?*' => Http::response(['files' => []])]);

    app(ServiceAccountDriveSource::class)->listChildren('root', null, true);

    Http::assertSent(function ($req) {
        parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);

        return isset($q['q']) && str_contains($q['q'], "mimeType='application/vnd.google-apps.folder'");
    });
});

it('throws instead of truncating when a folder exceeds list_max', function () {
    config()->set('innertia.files.drive.list_max', 1);
    Http::fake(tokenFake() + [API . '/files?*' => Http::response(['files' => [
        ['id' => 'a', 'name' => 'A', 'mimeType' => 'text/plain'],
        ['id' => 'b', 'name' => 'B', 'mimeType' => 'text/plain'],
    ]])]);

    app(ServiceAccountDriveSource::class)->listChildren();
})->throws(DriveException::class);

it('reads metadata and maps 404 to DriveItemNotFoundException', function () {
    Http::fake(tokenFake() + [
        API . '/files/ok?*'   => Http::response(['id' => 'ok', 'name' => 'Ok', 'mimeType' => 'text/plain', 'md5Checksum' => 'm']),
        API . '/files/gone?*' => Http::response(['error' => ['message' => 'File not found', 'errors' => [['reason' => 'notFound']]]], 404),
    ]);
    $src = app(ServiceAccountDriveSource::class);

    expect($src->metadata('ok')->md5)->toBe('m');
    expect(fn () => $src->metadata('gone'))->toThrow(DriveItemNotFoundException::class);
});

it('builds the path from My Drive and names shared drive roots', function () {
    Http::fake(tokenFake() + [
        API . '/files/c?*'    => Http::response(['id' => 'c', 'name' => 'C', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['b']]),
        API . '/files/b?*'    => Http::response(['id' => 'b', 'name' => 'B', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['root-id']]),
        API . '/files/root-id?*' => Http::response(['id' => 'root-id', 'name' => 'My Drive', 'mimeType' => 'application/vnd.google-apps.folder']),
        API . '/files/s2?*'   => Http::response(['id' => 's2', 'name' => 'S2', 'mimeType' => 'application/vnd.google-apps.folder', 'driveId' => 'sd', 'parents' => ['sd']]),
        API . '/files/sd?*'   => Http::response(['id' => 'sd', 'name' => 'Drive', 'mimeType' => 'application/vnd.google-apps.folder', 'driveId' => 'sd']),
        API . '/drives/sd?*'  => Http::response(['id' => 'sd', 'name' => 'Equipo']),
    ]);
    $src = app(ServiceAccountDriveSource::class);

    expect($src->path('c'))->toBe([['id' => 'root', 'name' => 'Mi unidad'], ['id' => 'b', 'name' => 'B']]);
    expect($src->path('s2'))->toBe([['id' => 'sd', 'name' => 'Equipo']]);
});

it('downloads to a temp file and enforces the size limit while streaming', function () {
    // Closure: Http::fake reutiliza el mismo stream PSR entre requests; uno fresco por request.
    Http::fake(tokenFake() + [API . '/files/bin?*' => fn () => Http::response(str_repeat('x', 3000))]);
    $src = app(ServiceAccountDriveSource::class);

    $path = $src->download('bin', 10_000);
    expect(file_get_contents($path))->toBe(str_repeat('x', 3000));
    @unlink($path);

    $before = glob(sys_get_temp_dir() . '/innertia-*') ?: [];
    expect(fn () => $src->download('bin', 100))->toThrow(DriveFileTooLargeException::class);
    expect(glob(sys_get_temp_dir() . '/innertia-*') ?: [])->toBe($before);
});

it('exports natives and maps the 10 MB limit', function () {
    Http::fake(tokenFake() + [
        API . '/files/doc/export?*' => Http::response('DOCX'),
        API . '/files/big/export?*' => Http::response(['error' => ['message' => 'too large', 'errors' => [['reason' => 'exportSizeLimitExceeded']]]], 403),
    ]);
    $src = app(ServiceAccountDriveSource::class);

    $path = $src->export('doc', 'application/pdf');
    expect(file_get_contents($path))->toBe('DOCX');
    @unlink($path);

    expect(fn () => $src->export('big', 'application/pdf'))->toThrow(DriveExportTooLargeException::class);
});

it('lists shared drives and creates folders', function () {
    Http::fake(tokenFake() + [
        API . '/drives?*' => Http::response(['drives' => [['id' => 'sd', 'name' => 'Equipo']]]),
        API . '/files?*'  => Http::response(['id' => 'nf', 'name' => 'Nueva', 'mimeType' => 'application/vnd.google-apps.folder']),
    ]);
    $src = app(ServiceAccountDriveSource::class);

    expect($src->sharedDrives())->toBe([['id' => 'sd', 'name' => 'Equipo']]);
    expect($src->createFolder('Nueva', 'root')->id)->toBe('nf');
    Http::assertSent(fn ($req) => $req->method() === 'POST' && str_contains($req->url(), API . '/files?')
        && $req['name'] === 'Nueva' && $req['parents'] === ['root']);
});

it('impersonates the configured drive subject and caches the token per subject', function () {
    Settings::set('integrations.google.drive_subject', 'reportes@dominio.cl');
    Http::fake(tokenFake() + [API . '/files?*' => Http::response(['files' => []])]);
    $src = app(ServiceAccountDriveSource::class);

    $src->listChildren();
    $src->listChildren();

    Http::assertSentCount(3); // 1 token + 2 listados
    Http::assertSent(function ($req) {
        if (! str_contains($req->url(), 'oauth2.googleapis.com/token')) {
            return false;
        }
        $payload = json_decode(base64_decode(strtr(explode('.', $req['assertion'])[1], '-_', '+/')), true);

        return $payload['sub'] === 'reportes@dominio.cl' && $payload['scope'] === 'https://www.googleapis.com/auth/drive';
    });
});

it('surfaces Google auth errors as DriveException', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'unauthorized_client', 'error_description' => 'Client is unauthorized'], 401)]);

    app(ServiceAccountDriveSource::class)->listChildren();
})->throws(DriveException::class, 'Client is unauthorized');

it('resolves the service account source, a bound source, or fails when not configured', function () {
    expect(DriveSourceResolver::resolve())->toBeInstanceOf(ServiceAccountDriveSource::class);
    expect(DriveSourceResolver::available())->toBeTrue();

    $fake = Mockery::mock(DriveSource::class);
    app()->instance(DriveSource::class, $fake);
    expect(DriveSourceResolver::resolve())->toBe($fake);
    app()->offsetUnset(DriveSource::class);

    Settings::forget('workspace.google.service_account_json');
    DriveSourceResolver::flush();
    expect(DriveSourceResolver::available())->toBeFalse();
    expect(fn () => DriveSourceResolver::resolve())->toThrow(DriveNotConfiguredException::class);
});

it('surfaces connection errors on the API as DriveException', function () {
    Http::fake(tokenFake() + [API . '/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

    try {
        app(ServiceAccountDriveSource::class)->listChildren();
        $this->fail('Debió lanzar DriveException');
    } catch (DriveException $e) {
        expect($e->reason)->toBe('connection')->and($e->getMessage())->toContain('timeout');
    }
});

it('surfaces connection errors on the token request as DriveException', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

    try {
        app(ServiceAccountDriveSource::class)->listChildren();
        $this->fail('Debió lanzar DriveException');
    } catch (DriveException $e) {
        expect($e->reason)->toBe('connection');
    }
});

it('keeps the shared drive root name when the drives lookup cannot connect', function () {
    Http::fake(tokenFake() + [
        API . '/files/s2?*'  => Http::response(['id' => 's2', 'name' => 'S2', 'mimeType' => 'application/vnd.google-apps.folder', 'driveId' => 'sd', 'parents' => ['sd']]),
        API . '/files/sd?*'  => Http::response(['id' => 'sd', 'name' => 'Drive', 'mimeType' => 'application/vnd.google-apps.folder', 'driveId' => 'sd']),
        API . '/drives/sd?*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
    ]);

    expect(app(ServiceAccountDriveSource::class)->path('s2'))->toBe([['id' => 'sd', 'name' => 'Drive']]);
});

function apiError(int $status, string $reason): \GuzzleHttp\Promise\PromiseInterface
{
    return Http::response(['error' => ['message' => $reason, 'errors' => [['reason' => $reason]]]], $status);
}

it('returns a partial path without inventing My Drive when a parent is not accessible', function () {
    Http::fake(tokenFake() + [
        API . '/files/c?*' => Http::response(['id' => 'c', 'name' => 'C', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['b']]),
        API . '/files/b?*' => Http::response(['id' => 'b', 'name' => 'B', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['ajeno']]),
        API . '/files/ajeno?*' => apiError(404, 'notFound'),
    ]);

    $path = app(ServiceAccountDriveSource::class)->path('c');

    expect($path)->toBe([['id' => 'b', 'name' => 'B']]);
    expect(array_column($path, 'name'))->not->toContain('Mi unidad');
});

it('returns a partial path on a 403 permission error', function () {
    Http::fake(tokenFake() + [
        API . '/files/c?*' => Http::response(['id' => 'c', 'name' => 'C', 'mimeType' => 'text/plain', 'parents' => ['b']]),
        API . '/files/b?*' => Http::response(['id' => 'b', 'name' => 'B', 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => ['x']]),
        API . '/files/x?*' => apiError(403, 'insufficientFilePermissions'),
    ]);
    expect(app(ServiceAccountDriveSource::class)->path('c'))->toBe([['id' => 'b', 'name' => 'B']]);
});

it('rethrows server errors while walking the path instead of cutting it', function () {
    Http::fake(tokenFake() + [
        API . '/files/c?*' => Http::response(['id' => 'c', 'name' => 'C', 'mimeType' => 'text/plain', 'parents' => ['b']]),
        API . '/files/b?*' => apiError(500, 'backendError'),
    ]);
    expect(fn () => app(ServiceAccountDriveSource::class)->path('c'))->toThrow(DriveException::class);
});

it('refreshes the cached token once on a 401 and retries the request', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::sequence()
            ->push(['access_token' => 'tok-1', 'expires_in' => 3600])
            ->push(['access_token' => 'tok-2', 'expires_in' => 3600]),
        API . '/files?*' => Http::sequence()
            ->push(['error' => ['message' => 'Invalid Credentials', 'errors' => [['reason' => 'authError']]]], 401)
            ->push(['files' => [['id' => 'a', 'name' => 'A', 'mimeType' => 'text/plain']]]),
    ]);

    $items = app(ServiceAccountDriveSource::class)->listChildren();

    expect($items)->toHaveCount(1);
    $tokenCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'oauth2.googleapis.com/token'));
    expect($tokenCalls)->toHaveCount(2);
    Http::assertSent(fn ($req) => str_contains($req->url(), API . '/files?') && $req->hasHeader('Authorization', 'Bearer tok-2'));
});

it('does not loop on a persistent 401', function () {
    Http::fake(tokenFake() + [API . '/files?*' => apiError(401, 'authError')]);

    expect(fn () => app(ServiceAccountDriveSource::class)->listChildren())->toThrow(DriveException::class);
    Http::assertSentCount(4); // token + 401 + token nuevo + 401
});

it('uses a different token cache key when the service account key rotates', function () {
    Http::fake(tokenFake() + [API . '/files?*' => Http::response(['files' => []])]);
    $src = app(ServiceAccountDriveSource::class);

    $src->listChildren();
    $src->listChildren();
    seedServiceAccount(); // nueva private_key, mismo client_email y subject
    $src->listChildren();

    $tokenCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'oauth2.googleapis.com/token'));
    expect($tokenCalls)->toHaveCount(2);
});

it('caches the token for expires_in minus a safety margin', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok-1', 'expires_in' => 1000])]
        + [API . '/files?*' => Http::response(['files' => []])]);
    $src = app(ServiceAccountDriveSource::class);

    $src->listChildren();
    $this->travel(701)->seconds();
    $src->listChildren();

    $tokenCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'oauth2.googleapis.com/token'));
    expect($tokenCalls)->toHaveCount(2);
});

it('retries rate limits with backoff and then succeeds', function () {
    Http::fake(tokenFake() + [
        API . '/files?*' => Http::sequence()
            ->push(['error' => ['message' => 'Too many', 'errors' => [['reason' => 'rateLimitExceeded']]]], 429)
            ->push(['files' => [['id' => 'a', 'name' => 'A', 'mimeType' => 'text/plain']]]),
    ]);

    expect(app(ServiceAccountDriveSource::class)->listChildren())->toHaveCount(1);
    Sleep::assertSleptTimes(1);
});

it('gives up after 3 retries on a 403 userRateLimitExceeded', function () {
    Http::fake(tokenFake() + [API . '/files?*' => apiError(403, 'userRateLimitExceeded')]);

    try {
        app(ServiceAccountDriveSource::class)->listChildren();
        $this->fail('Debió lanzar DriveException');
    } catch (DriveException $e) {
        expect($e->reason)->toBe('userRateLimitExceeded');
    }
    Http::assertSentCount(5); // token + 4 intentos
    Sleep::assertSleptTimes(3);
});

it('does not retry a 403 insufficientPermissions', function () {
    Http::fake(tokenFake() + [API . '/files?*' => apiError(403, 'insufficientPermissions')]);

    expect(fn () => app(ServiceAccountDriveSource::class)->listChildren())->toThrow(DriveException::class);
    Http::assertSentCount(2);
    Sleep::assertNeverSlept();
});

it('retries the token request on 5xx', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::sequence()
            ->push(['error' => 'backend'], 503)
            ->push(['access_token' => 'tok-1', 'expires_in' => 3600]),
        API . '/files?*' => Http::response(['files' => []]),
    ]);

    expect(app(ServiceAccountDriveSource::class)->listChildren())->toBe([]);
    Sleep::assertSleptTimes(1);
});

it('maps a missing folder on list and create to DriveItemNotFoundException', function () {
    Http::fake(tokenFake() + [
        API . '/files?*' => apiError(404, 'notFound'),
    ]);
    $src = app(ServiceAccountDriveSource::class);

    expect(fn () => $src->listChildren('nope'))->toThrow(DriveItemNotFoundException::class);
    expect(fn () => $src->createFolder('X', 'nope'))->toThrow(DriveItemNotFoundException::class);
});

it('maps download-disabled 403s to DriveItemNotImportableException', function () {
    Http::fake(tokenFake() + [API . '/files/locked?*' => apiError(403, 'cannotDownloadFile')]);

    try {
        app(ServiceAccountDriveSource::class)->download('locked', 1000);
        $this->fail('Debió lanzar DriveItemNotImportableException');
    } catch (DriveItemNotImportableException $e) {
        expect($e->getMessage())->toBe('El propietario deshabilitó la descarga de este archivo.');
    }
});
