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
