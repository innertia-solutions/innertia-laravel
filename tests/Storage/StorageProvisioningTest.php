<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Innertia\Facades\Storage;
use Innertia\Storage\Provisioning\ProvisioningException;
use Innertia\Storage\Provisioning\StorageProvisioner;

beforeEach(function () {
    StorageProvisioner::$retryDelayMs = 0;
});

function fakeServiceAccountJson(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    return json_encode([
        'type'         => 'service_account',
        'project_id'   => 'proj-1',
        'client_email' => 'admin@proj-1.iam.gserviceaccount.com',
        'private_key'  => $pem,
        'token_uri'    => 'https://oauth2.googleapis.com/token',
    ]);
}

describe('GCS', function () {
    it('crea bucket, cuenta dedicada, rol sobre el bucket y HMAC', function () {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            'storage.googleapis.com/storage/v1/b?project=proj-1' => Http::response(['name' => 'docs-bucket']),
            'iam.googleapis.com/v1/projects/proj-1/serviceAccounts' => Http::response(['email' => 'storage-docs-bucket@proj-1.iam.gserviceaccount.com']),
            'storage.googleapis.com/storage/v1/b/docs-bucket/iam' => Http::sequence()
                ->push(['etag' => 'E1', 'bindings' => [['role' => 'roles/storage.legacyBucketOwner', 'members' => ['projectOwner:proj-1']]]])
                ->push(['etag' => 'E2']),
            'storage.googleapis.com/storage/v1/projects/proj-1/hmacKeys*' => Http::response(['metadata' => ['accessId' => 'GOOG1EXAMPLE'], 'secret' => 'hmac-secret']),
        ]);

        $r = Storage::provision('gcs', ['service_account_json' => fakeServiceAccountJson(), 'bucket' => 'Docs-Bucket', 'location' => 'southamerica-west1']);

        expect($r['settings'])->toMatchArray([
            'provider' => 'gcs', 'bucket' => 'docs-bucket',
            'access_key_id' => 'GOOG1EXAMPLE', 'secret_access_key' => 'hmac-secret',
        ]);

        Http::assertSent(fn (Request $req) => $req->url() === 'https://storage.googleapis.com/storage/v1/b?project=proj-1'
            && $req['location'] === 'SOUTHAMERICA-WEST1'
            && $req['iamConfiguration']['publicAccessPrevention'] === 'enforced');
        // El rol se agrega SOLO sobre el bucket, conservando los bindings existentes y el etag.
        Http::assertSent(fn (Request $req) => $req->method() === 'PUT'
            && $req['etag'] === 'E1'
            && collect($req['bindings'])->contains(fn ($b) => $b['role'] === 'roles/storage.objectAdmin'
                && $b['members'] === ['serviceAccount:storage-docs-bucket@proj-1.iam.gserviceaccount.com'])
            && collect($req['bindings'])->contains(fn ($b) => $b['role'] === 'roles/storage.legacyBucketOwner'));
        // La HMAC es de la cuenta dedicada, no de la admin.
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'hmacKeys?serviceAccountEmail=storage-docs-bucket%40proj-1'));
    });

    it('sin permiso para crear cuentas usa la cuenta admin y lo avisa', function () {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            'storage.googleapis.com/storage/v1/b?project=proj-1' => Http::response([], 200),
            'iam.googleapis.com/*' => Http::response(['error' => ['message' => 'Permission denied']], 403),
            'storage.googleapis.com/storage/v1/projects/proj-1/hmacKeys*' => Http::response(['metadata' => ['accessId' => 'GOOG1ADMIN'], 'secret' => 's']),
        ]);

        $r = Storage::provision('gcs', ['service_account_json' => fakeServiceAccountJson(), 'bucket' => 'docs-bucket']);

        expect($r['settings']['access_key_id'])->toBe('GOOG1ADMIN')
            ->and(implode(' ', $r['details']))->toContain('Service Account Admin');
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'serviceAccountEmail=admin%40proj-1'));
        Http::assertNotSent(fn (Request $req) => str_ends_with($req->url(), '/iam') && $req->method() === 'PUT');
    });

    it('reusa bucket y cuenta dedicada y elimina las claves HMAC anteriores de esa cuenta', function () {
        $hmac = 'https://storage.googleapis.com/storage/v1/projects/proj-1/hmacKeys';
        Http::fake(function (Request $req) use ($hmac) {
            $url = $req->url();

            return match (true) {
                str_contains($url, 'oauth2.googleapis.com')                            => Http::response(['access_token' => 'tok']),
                str_ends_with($url, '/b?project=proj-1')                               => Http::response(['error' => ['message' => 'conflict']], 409),
                str_ends_with($url, '/b/docs-bucket')                                  => Http::response(['name' => 'docs-bucket']),
                str_contains($url, 'iam.googleapis.com')                               => Http::response(['error' => ['message' => 'exists']], 409),
                str_ends_with($url, '/b/docs-bucket/iam')                              => Http::response(['etag' => 'E1', 'bindings' => []]),
                $req->method() === 'GET' && str_starts_with($url, $hmac.'?')           => Http::response(['items' => [
                    ['accessId' => 'OLD1', 'state' => 'ACTIVE'],
                    ['accessId' => 'OLD2', 'state' => 'INACTIVE'],
                ]]),
                $req->method() === 'POST' && str_starts_with($url, $hmac.'?')          => Http::response(['metadata' => ['accessId' => 'NEW'], 'secret' => 's']),
                str_starts_with($url, $hmac.'/')                                       => Http::response([], $req->method() === 'DELETE' ? 204 : 200),
            };
        });

        $r = Storage::provision('gcs', ['service_account_json' => fakeServiceAccountJson(), 'bucket' => 'docs-bucket']);

        expect($r['settings']['access_key_id'])->toBe('NEW')
            ->and(implode(' ', $r['details']))->toContain('Bucket existente reutilizado')
            ->and(implode(' ', $r['details']))->toContain('2 clave(s) HMAC anteriores');
        // ACTIVE → se desactiva antes de borrar (GCS no borra claves activas).
        Http::assertSent(fn (Request $req) => $req->method() === 'PUT' && $req->url() === $hmac.'/OLD1' && $req['state'] === 'INACTIVE');
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE' && $req->url() === $hmac.'/OLD1');
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE' && $req->url() === $hmac.'/OLD2');
        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'hmacKeys/NEW'));
        // Las claves viejas se listan SOLO para la cuenta dedicada.
        Http::assertSent(fn (Request $req) => $req->method() === 'GET' && str_contains($req->url(), 'hmacKeys?serviceAccountEmail=storage-docs-bucket%40proj-1'));
    });

    it('con el límite de claves HMAC lleno: limpia las anteriores y reintenta', function () {
        $hmac = 'https://storage.googleapis.com/storage/v1/projects/proj-1/hmacKeys';
        $old = collect(range(1, 10))->map(fn ($i) => ['accessId' => "OLD{$i}", 'state' => 'INACTIVE'])->all();
        $posts = 0;
        Http::fake(function (Request $req) use ($hmac, $old, &$posts) {
            $url = $req->url();

            return match (true) {
                str_contains($url, 'oauth2.googleapis.com')                   => Http::response(['access_token' => 'tok']),
                str_ends_with($url, '/b?project=proj-1')                      => Http::response(['error' => ['message' => 'conflict']], 409),
                str_ends_with($url, '/b/docs-bucket')                         => Http::response(['name' => 'docs-bucket']),
                str_contains($url, 'iam.googleapis.com')                      => Http::response(['error' => ['message' => 'exists']], 409),
                str_ends_with($url, '/b/docs-bucket/iam')                     => Http::response(['etag' => 'E1', 'bindings' => []]),
                $req->method() === 'GET' && str_starts_with($url, $hmac.'?')  => Http::response(['items' => $old]),
                $req->method() === 'POST' && str_starts_with($url, $hmac.'?') => ++$posts === 1
                    ? Http::response(['error' => ['message' => 'Too many HMAC keys for this service account.']], 400)
                    : Http::response(['metadata' => ['accessId' => 'NEW'], 'secret' => 's']),
                str_starts_with($url, $hmac.'/')                              => Http::response([], 204),
            };
        });

        $r = Storage::provision('gcs', ['service_account_json' => fakeServiceAccountJson(), 'bucket' => 'docs-bucket']);

        expect($r['settings']['access_key_id'])->toBe('NEW')->and($posts)->toBe(2);
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE' && $req->url() === $hmac.'/OLD10');
    });

    it('nunca borra claves HMAC de la cuenta admin', function () {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            'storage.googleapis.com/storage/v1/b?project=proj-1' => Http::response([], 200),
            'iam.googleapis.com/*' => Http::response(['error' => ['message' => 'Permission denied']], 403),
            'storage.googleapis.com/storage/v1/projects/proj-1/hmacKeys*' => Http::response(['metadata' => ['accessId' => 'GOOG1ADMIN'], 'secret' => 's']),
        ]);

        Storage::provision('gcs', ['service_account_json' => fakeServiceAccountJson(), 'bucket' => 'docs-bucket']);

        Http::assertNotSent(fn (Request $req) => str_contains($req->url(), 'hmacKeys') && in_array($req->method(), ['GET', 'PUT', 'DELETE'], true));
    });

    it('bucket de otro proyecto: error claro', function () {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok']),
            'storage.googleapis.com/storage/v1/b?project=proj-1' => Http::response(['error' => ['message' => 'conflict']], 409),
            'storage.googleapis.com/storage/v1/b/taken' => Http::response(['error' => ['message' => 'forbidden']], 403),
        ]);

        Storage::provision('gcs', ['service_account_json' => fakeServiceAccountJson(), 'bucket' => 'taken']);
    })->throws(ProvisioningException::class, 'no pertenece a este proyecto');

    it('rechaza un JSON que no es de cuenta de servicio', function () {
        Storage::provision('gcs', ['service_account_json' => '{"type":"authorized_user"}', 'bucket' => 'docs-bucket']);
    })->throws(ProvisioningException::class, 'cuenta de servicio');
});

describe('R2', function () {
    it('crea bucket y token limitado al bucket; secret = sha256 del token', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts' => Http::response(['success' => true, 'result' => [['id' => 'acc123']]]),
            'api.cloudflare.com/client/v4/accounts/acc123/r2/buckets' => Http::response(['success' => true, 'result' => ['name' => 'docs']]),
            'api.cloudflare.com/client/v4/user/tokens/permission_groups' => Http::response(['success' => true, 'result' => [
                ['id' => 'g-read', 'name' => 'Workers R2 Storage Bucket Item Read'],
                ['id' => 'g-write', 'name' => 'Workers R2 Storage Bucket Item Write'],
            ]]),
            'api.cloudflare.com/client/v4/user/tokens' => Http::response(['success' => true, 'result' => ['id' => 'tok-id', 'value' => 'tok-value']]),
        ]);

        $r = Storage::provision('r2', ['api_token' => 'admin', 'bucket' => 'docs']);

        expect($r['settings'])->toMatchArray([
            'provider' => 'r2', 'bucket' => 'docs',
            'endpoint' => 'https://acc123.r2.cloudflarestorage.com',
            'access_key_id' => 'tok-id', 'secret_access_key' => hash('sha256', 'tok-value'),
        ]);
        Http::assertSent(fn (Request $req) => $req->url() === 'https://api.cloudflare.com/client/v4/user/tokens'
            && $req['policies'][0]['resources'] === ['com.cloudflare.edge.r2.bucket.acc123_default_docs' => '*']
            && $req['policies'][0]['permission_groups'] === [['id' => 'g-write']]);
    });

    it('token de cuenta: cae a /accounts/{id}/tokens y reutiliza bucket existente', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts/acc9/r2/buckets' => Http::response(['success' => false, 'errors' => [['code' => 10004, 'message' => 'The bucket you tried to create already exists']]], 409),
            'api.cloudflare.com/client/v4/accounts/acc9/r2/buckets/docs' => Http::response(['success' => true, 'result' => ['name' => 'docs']]),
            'api.cloudflare.com/client/v4/user/tokens/permission_groups' => Http::response(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Unauthorized']]], 403),
            'api.cloudflare.com/client/v4/accounts/acc9/tokens/permission_groups' => Http::response(['success' => true, 'result' => [['id' => 'g-write', 'name' => 'Workers R2 Storage Bucket Item Write']]]),
            'api.cloudflare.com/client/v4/accounts/acc9/tokens' => Http::response(['success' => true, 'result' => ['id' => 'acct-tok', 'value' => 'v']]),
        ]);

        $r = Storage::provision('r2', ['api_token' => 'admin', 'bucket' => 'docs', 'account_id' => 'acc9']);

        expect($r['settings']['access_key_id'])->toBe('acct-tok')
            ->and($r['details'][0])->toContain('existente');
    });

    it('al reusar el bucket elimina los tokens anteriores del mismo bucket', function () {
        $base = 'https://api.cloudflare.com/client/v4/user/tokens';
        Http::fake(function (Request $req) use ($base) {
            $url = $req->url();

            return match (true) {
                str_ends_with($url, '/accounts/acc9/r2/buckets')        => Http::response(['success' => false, 'errors' => [['code' => 10004, 'message' => 'exists']]], 409),
                str_ends_with($url, '/accounts/acc9/r2/buckets/docs')   => Http::response(['success' => true, 'result' => ['name' => 'docs']]),
                str_ends_with($url, '/user/tokens/permission_groups')   => Http::response(['success' => true, 'result' => [['id' => 'g-write', 'name' => 'Workers R2 Storage Bucket Item Write']]]),
                $req->method() === 'POST' && $url === $base             => Http::response(['success' => true, 'result' => ['id' => 'new-tok', 'value' => 'v']]),
                $req->method() === 'GET' && str_starts_with($url, $base) => Http::response(['success' => true, 'result' => [
                    ['id' => 'old-tok', 'name' => 'innertia-storage-docs'],
                    ['id' => 'new-tok', 'name' => 'innertia-storage-docs'],
                    ['id' => 'other', 'name' => 'innertia-storage-otro'],
                ]]),
                $req->method() === 'DELETE'                             => Http::response(['success' => true, 'result' => []]),
            };
        });

        $r = Storage::provision('r2', ['api_token' => 'admin', 'bucket' => 'docs', 'account_id' => 'acc9']);

        expect($r['settings']['access_key_id'])->toBe('new-tok')
            ->and(implode(' ', $r['details']))->toContain('1 token(s) anteriores');
        Http::assertSent(fn (Request $req) => $req->method() === 'DELETE' && $req->url() === $base.'/old-tok');
        Http::assertNotSent(fn (Request $req) => $req->method() === 'DELETE' && in_array($req->url(), [$base.'/new-tok', $base.'/other'], true));
    });

    it('sin permiso para crear tokens: error con el permiso faltante', function () {
        Http::fake([
            'api.cloudflare.com/client/v4/accounts/acc9/r2/buckets' => Http::response(['success' => true, 'result' => []]),
            'api.cloudflare.com/client/v4/*/permission_groups' => Http::response(['success' => false], 403),
        ]);

        Storage::provision('r2', ['api_token' => 'admin', 'bucket' => 'docs', 'account_id' => 'acc9']);
    })->throws(ProvisioningException::class, 'API Tokens: Edit');

    it('valida el nombre del bucket', function () {
        Storage::provision('r2', ['api_token' => 'admin', 'bucket' => 'Mal_Nombre!']);
    })->throws(ProvisioningException::class, 'Nombre de bucket inválido');
});

it('s3 no admite configuración automática', function () {
    Storage::provision('s3', []);
})->throws(ProvisioningException::class, 'no admite');
