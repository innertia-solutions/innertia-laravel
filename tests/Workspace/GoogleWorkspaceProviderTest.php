<?php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Innertia\Facades\Settings;
use Innertia\Workspace\Providers\GoogleWorkspaceProvider;
use Innertia\Workspace\WorkspaceException;

uses(RefreshDatabase::class);

/** Siembra una service account de test con una RSA key real (para que JWT::encode RS256 funcione). */
function seedServiceAccount(): void
{
    $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($res, $priv);
    $json = json_encode([
        'client_email' => 'sa@proj.iam.gserviceaccount.com',
        'private_key'  => $priv,
        'token_uri'    => 'https://oauth2.googleapis.com/token',
    ]);
    Settings::set('workspace.google.service_account_json', $json, 'string', encrypted: true);
    Settings::set('workspace.google.admin_email', 'admin@dominio.cl');
}

beforeEach(function () {
    // El provider cachea el access token con Cache::remember (clave por admin). Entre tests el
    // driver array persiste, así que lo limpiamos para que cada test ejerza el flujo OAuth completo.
    Cache::flush();
    seedServiceAccount();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600]),
        // La colección /users se pide con query string en el GET de lista (?customer=…); el patrón
        // lleva `?*` para casar la lista sin casar los endpoints de miembro (/users/{id}).
        'admin.googleapis.com/admin/directory/v1/users?*' => Http::response([
            'users' => [[
                'id' => '42', 'primaryEmail' => 'juan@dominio.cl',
                'name' => ['fullName' => 'Juan Pérez', 'givenName' => 'Juan', 'familyName' => 'Pérez'],
                'suspended' => false, 'isAdmin' => false,
                'lastLoginTime' => '2026-10-01T10:00:00Z', 'creationTime' => '2026-01-01T10:00:00Z',
            ]],
            'nextPageToken' => 'next-1',
        ]),
        // El POST de createUser va a /users sin query string ni slash final.
        'admin.googleapis.com/admin/directory/v1/users' => Http::response([
            'id' => '99', 'primaryEmail' => 'nuevo@dominio.cl',
            'name' => ['fullName' => 'Ana Soto', 'givenName' => 'Ana', 'familyName' => 'Soto'],
            'suspended' => false, 'isAdmin' => false,
        ]),
        'admin.googleapis.com/admin/directory/v1/users/*' => Http::response([
            'id' => '42', 'primaryEmail' => 'juan@dominio.cl', 'suspended' => true,
            'name' => ['fullName' => 'Juan Pérez', 'givenName' => 'Juan', 'familyName' => 'Pérez'],
        ]),
    ]);
});

it('configured true cuando hay creds', function () {
    expect(app(GoogleWorkspaceProvider::class)->configured())->toBeTrue();
});

it('listUsers mapea al shape común y pasa el token + customer', function () {
    $out = app(GoogleWorkspaceProvider::class)->listUsers('juan');
    expect($out['users'][0]['email'])->toBe('juan@dominio.cl');
    expect($out['users'][0]['given_name'])->toBe('Juan');
    expect($out['next_page_token'])->toBe('next-1');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'customer=my_customer')
        && $r->hasHeader('Authorization', 'Bearer tok-123'));
});

it('createUser postea el body correcto', function () {
    app(GoogleWorkspaceProvider::class)->createUser('nuevo@dominio.cl', 'Ana', 'Soto', 'Secreta123', true);
    Http::assertSent(fn ($r) => $r->method() === 'POST'
        && str_ends_with($r->url(), '/users')
        && $r['primaryEmail'] === 'nuevo@dominio.cl'
        && $r['name']['givenName'] === 'Ana'
        && $r['changePasswordAtNextLogin'] === true);
});

it('resetPassword hace PATCH con password + changeNext', function () {
    app(GoogleWorkspaceProvider::class)->resetPassword('42', 'NuevaPass9', true);
    Http::assertSent(fn ($r) => $r->method() === 'PATCH'
        && str_contains($r->url(), '/users/42')
        && $r['password'] === 'NuevaPass9'
        && $r['changePasswordAtNextLogin'] === true);
});

it('setSuspended hace PATCH suspended', function () {
    app(GoogleWorkspaceProvider::class)->setSuspended('42', true);
    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['suspended'] === true);
});

it('deleteUser hace DELETE', function () {
    app(GoogleWorkspaceProvider::class)->deleteUser('42');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/users/42'));
});

it('validate ok cuando el list responde', function () {
    expect(app(GoogleWorkspaceProvider::class)->validate()['ok'])->toBeTrue();
});

it('un error de Google lanza WorkspaceException', function () {
    // Http::fake() en Laravel apila stubs (no reemplaza); reseteamos la factory para que este
    // fake de error gane sobre los stubs 2xx registrados en beforeEach.
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
        'admin.googleapis.com/*' => Http::response(['error' => ['message' => 'Not Authorized']], 403),
    ]);
    app(GoogleWorkspaceProvider::class)->deleteUser('42');
})->throws(WorkspaceException::class, 'Not Authorized');
