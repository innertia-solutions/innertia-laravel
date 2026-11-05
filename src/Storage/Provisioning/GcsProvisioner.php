<?php

namespace Innertia\Storage\Provisioning;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google Cloud Storage. Con el JSON de una cuenta de servicio ADMIN:
 *   1. crea el bucket (acceso uniforme + prevención de acceso público),
 *   2. crea una cuenta de servicio dedicada (`storage-<bucket>`),
 *   3. le da `roles/storage.objectAdmin` SOLO sobre ese bucket,
 *   4. emite una clave HMAC para ella (Access ID + Secret → driver s3).
 *
 * Roles de la cuenta admin en el proyecto: Storage Admin + Storage HMAC Key Admin +
 * Service Account Admin. Sin el último, usa la propia cuenta admin para la HMAC (con aviso).
 */
class GcsProvisioner extends StorageProvisioner
{
    public const SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    private const STORAGE = 'https://storage.googleapis.com/storage/v1';

    private const IAM = 'https://iam.googleapis.com/v1';

    /** Máximo de claves HMAC por cuenta de servicio en GCS. */
    private const HMAC_LIMIT = 10;

    private string $token;

    public function provision(array $input): array
    {
        $sa = $this->serviceAccount($input['service_account_json'] ?? null);
        $project = trim((string) ($input['project_id'] ?? '')) ?: ($sa['project_id'] ?? '');
        if ($project === '') {
            throw new ProvisioningException('No se pudo determinar el proyecto de Google Cloud (project_id).');
        }
        $bucket = $this->assertBucketName($input['bucket'] ?? null);
        $location = strtoupper(trim((string) ($input['location'] ?? ''))) ?: 'US';
        $details = [];

        $this->token = $this->accessToken($sa);

        // 1. Bucket
        $res = $this->http()->post(self::STORAGE.'/b?project='.urlencode($project), [
            'name'             => $bucket,
            'location'         => $location,
            'iamConfiguration' => [
                'uniformBucketLevelAccess' => ['enabled' => true],
                'publicAccessPrevention'   => 'enforced',
            ],
        ]);
        if ($res->status() === 409) {
            $own = $this->http()->get(self::STORAGE.'/b/'.$bucket);
            if ($own->failed()) {
                throw new ProvisioningException("El bucket \"{$bucket}\" ya existe y no pertenece a este proyecto (los nombres son globales). Elige otro nombre.");
            }
            $details[] = "Bucket existente reutilizado: {$bucket}.";
        } else {
            $this->ok($res, 'crear el bucket');
            $details[] = "Bucket creado: {$bucket} ({$location}, privado, acceso uniforme).";
        }

        // 2. Cuenta de servicio dedicada
        $email = $this->dedicatedServiceAccount($project, $bucket, $details) ?? $sa['client_email'];

        // 3. Rol sobre el bucket (solo para la dedicada: la admin ya es Storage Admin)
        if ($email !== $sa['client_email']) {
            $this->retry(
                fn () => $this->grantBucketRole($bucket, $email),
                fn ($e) => $e instanceof ProvisioningException && str_contains($e->getMessage(), 'does not exist'),
            );
            $details[] = "Rol Storage Object Admin sobre el bucket para {$email}.";
        }

        // 4. HMAC. Al reusar la cuenta dedicada, sus claves anteriores quedan obsoletas:
        //    se borran (GCS admite máx. 10 por cuenta; si el límite ya está lleno se
        //    limpian antes y se reintenta). Nunca se tocan las claves de la cuenta admin.
        $hmac = self::STORAGE.'/projects/'.urlencode($project).'/hmacKeys';
        $dedicated = $email !== $sa['client_email'];
        $old = $dedicated ? $this->hmacKeys($hmac, $email) : [];

        $res = $this->http()->post($hmac.'?serviceAccountEmail='.urlencode($email));
        if ($res->failed() && $old && count($old) >= self::HMAC_LIMIT) {
            $this->deleteHmacKeys($hmac, $old);
            $old = [];
            $res = $this->http()->post($hmac.'?serviceAccountEmail='.urlencode($email));
        }
        $this->ok($res, 'crear la clave HMAC');
        $accessId = $res->json('metadata.accessId');
        $secret = $res->json('secret');
        if (! $accessId || ! $secret) {
            throw new ProvisioningException('Google no devolvió la clave HMAC.');
        }
        $details[] = "Clave HMAC emitida ({$accessId}).";

        $old = array_values(array_filter($old, fn ($k) => ($k['accessId'] ?? null) !== $accessId));
        if ($old) {
            $deleted = $this->deleteHmacKeys($hmac, $old);
            $details[] = "{$deleted} clave(s) HMAC anteriores de {$email} eliminadas.";
        }

        return [
            'settings' => [
                'provider'          => 'gcs',
                'bucket'            => $bucket,
                'endpoint'          => null,
                'region'            => null,
                'access_key_id'     => $accessId,
                'secret_access_key' => $secret,
            ],
            'details' => $details,
        ];
    }

    /** Crea (o reutiliza) `storage-<bucket>`; null si la cuenta admin no puede crear cuentas. */
    private function dedicatedServiceAccount(string $project, string $bucket, array &$details): ?string
    {
        $accountId = $this->accountId($bucket);
        $email = "{$accountId}@{$project}.iam.gserviceaccount.com";

        $res = $this->http()->post(self::IAM.'/projects/'.urlencode($project).'/serviceAccounts', [
            'accountId'      => $accountId,
            'serviceAccount' => [
                'displayName' => Str::limit("Storage {$bucket}", 100, ''),
                'description' => 'Acceso de la plataforma al bucket '.$bucket.' (creada automáticamente).',
            ],
        ]);

        if ($res->status() === 409) {
            $details[] = "Cuenta de servicio existente reutilizada: {$email}.";

            return $email;
        }
        if ($res->status() === 403) {
            $details[] = 'Aviso: la cuenta admin no puede crear cuentas de servicio (falta "Service Account Admin"); '
                .'la clave HMAC queda asociada a la propia cuenta admin, con permisos sobre todo el proyecto.';

            return null;
        }
        $this->ok($res, 'crear la cuenta de servicio');
        $details[] = "Cuenta de servicio dedicada creada: {$email}.";

        return $res->json('email') ?: $email;
    }

    /** Claves HMAC (no borradas) de la cuenta; [] si no se pueden listar. */
    private function hmacKeys(string $base, string $email): array
    {
        $res = $this->http()->get($base.'?serviceAccountEmail='.urlencode($email));

        return $res->successful() ? array_values($res->json('items') ?? []) : [];
    }

    /** Desactiva (si está activa) y borra cada clave. Best-effort: devuelve cuántas borró. */
    private function deleteHmacKeys(string $base, array $keys): int
    {
        $deleted = 0;
        foreach ($keys as $key) {
            $id = $key['accessId'] ?? null;
            if (! $id) {
                continue;
            }
            if (($key['state'] ?? null) === 'ACTIVE') {
                $this->http()->put($base.'/'.urlencode($id), ['state' => 'INACTIVE']);
            }
            if ($this->http()->delete($base.'/'.urlencode($id))->successful()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function grantBucketRole(string $bucket, string $email): void
    {
        $res = $this->http()->get(self::STORAGE.'/b/'.$bucket.'/iam');
        $this->ok($res, 'leer los permisos del bucket');
        $policy = $res->json();
        $member = 'serviceAccount:'.$email;
        $role = 'roles/storage.objectAdmin';

        $bindings = $policy['bindings'] ?? [];
        $found = false;
        foreach ($bindings as &$b) {
            if (($b['role'] ?? null) === $role) {
                $found = true;
                if (! in_array($member, $b['members'] ?? [], true)) {
                    $b['members'][] = $member;
                }
            }
        }
        unset($b);
        if (! $found) {
            $bindings[] = ['role' => $role, 'members' => [$member]];
        }

        $res = $this->http()->put(self::STORAGE.'/b/'.$bucket.'/iam', array_filter([
            'bindings' => $bindings,
            'etag'     => $policy['etag'] ?? null,
        ]));
        $this->ok($res, 'dar acceso al bucket');
    }

    /** accountId válido de GCP: 6–30, [a-z][a-z0-9-]*[a-z0-9]. */
    private function accountId(string $bucket): string
    {
        $slug = preg_replace('/[^a-z0-9-]/', '-', $bucket);
        $id = rtrim(substr('storage-'.$slug, 0, 30), '-');

        return strlen($id) < 6 ? str_pad($id, 6, '0') : $id;
    }

    private function serviceAccount(?string $json): array
    {
        $sa = json_decode((string) $json, true);
        if (! is_array($sa) || ($sa['type'] ?? null) !== 'service_account' || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new ProvisioningException('El JSON no es una clave de cuenta de servicio de Google (type=service_account, client_email, private_key).');
        }

        return $sa;
    }

    private function accessToken(array $sa): string
    {
        $uri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        try {
            $assertion = JWT::encode([
                'iss' => $sa['client_email'], 'scope' => self::SCOPE, 'aud' => $uri,
                'iat' => $now, 'exp' => $now + 3600,
            ], $sa['private_key'], 'RS256');
        } catch (\Throwable) {
            throw new ProvisioningException('La private_key del JSON no es válida.');
        }

        $res = Http::asForm()->timeout(20)->post($uri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);
        if ($res->failed() || ! $res->json('access_token')) {
            throw new ProvisioningException('Google rechazó la cuenta de servicio: '.($res->json('error_description') ?? $res->json('error') ?? 'HTTP '.$res->status()));
        }

        return $res->json('access_token');
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)->acceptJson()->timeout(30);
    }

    private function ok(Response $res, string $step): void
    {
        if ($res->successful()) {
            return;
        }
        $msg = $res->json('error.message') ?? ('HTTP '.$res->status());
        if ($res->status() === 403) {
            $msg .= ' (revisa los roles de la cuenta: Storage Admin, Storage HMAC Key Admin y Service Account Admin)';
        }
        throw new ProvisioningException("No se pudo {$step}: {$msg}");
    }
}
