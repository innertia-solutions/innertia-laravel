<?php

namespace Innertia\Storage\Provisioning;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare R2. Con un token de API ADMIN:
 *   1. crea el bucket (o reutiliza uno propio),
 *   2. crea un token nuevo con "Workers R2 Storage Bucket Item Write" SOLO sobre ese bucket,
 *   3. credencial S3 = (id del token, SHA-256 del valor del token) — así lo define R2.
 *
 * Permisos del token admin: "Workers R2 Storage: Edit" (cuenta) + "API Tokens: Edit"
 * (usuario; o "Account API Tokens: Edit" si es un token de cuenta).
 */
class R2Provisioner extends StorageProvisioner
{
    public const API = 'https://api.cloudflare.com/client/v4';

    public const OBJECT_WRITE_GROUP = 'Workers R2 Storage Bucket Item Write';

    private string $token;

    public function provision(array $input): array
    {
        $this->token = trim((string) ($input['api_token'] ?? ''));
        if ($this->token === '') {
            throw new ProvisioningException('Falta el token de API de Cloudflare.');
        }
        $bucket = $this->assertBucketName($input['bucket'] ?? null);
        $account = trim((string) ($input['account_id'] ?? '')) ?: $this->onlyAccount();
        $details = [];

        // 1. Bucket
        $res = $this->http()->post(self::API."/accounts/{$account}/r2/buckets", array_filter([
            'name'         => $bucket,
            'locationHint' => ($input['location'] ?? null) ?: null,
        ]));
        if ($this->hasError($res, 10004)) { // the bucket already exists
            $own = $this->http()->get(self::API."/accounts/{$account}/r2/buckets/{$bucket}");
            $this->ok($own, 'usar el bucket existente');
            $details[] = "Bucket existente reutilizado: {$bucket}.";
        } else {
            $this->ok($res, 'crear el bucket');
            $details[] = "Bucket creado: {$bucket} (privado).";
        }

        // 2. Token limitado al bucket (token de usuario o, si no, de cuenta)
        [$base, $groupId] = $this->permissionGroup($account);
        $res = $this->http()->post(self::API.$base, [
            'name'     => "innertia-storage-{$bucket}",
            'policies' => [[
                'effect'            => 'allow',
                'resources'         => ["com.cloudflare.edge.r2.bucket.{$account}_default_{$bucket}" => '*'],
                'permission_groups' => [['id' => $groupId]],
            ]],
        ]);
        $this->ok($res, 'crear el token del bucket');
        $id = $res->json('result.id');
        $value = $res->json('result.value');
        if (! $id || ! $value) {
            throw new ProvisioningException('Cloudflare no devolvió el token creado.');
        }
        $details[] = "Token con lectura/escritura solo sobre {$bucket} creado (innertia-storage-{$bucket}).";

        // Re-ejecutar sobre el mismo bucket deja obsoletos los tokens anteriores con ese nombre.
        $deleted = $this->deleteOldTokens($base, "innertia-storage-{$bucket}", $id);
        if ($deleted > 0) {
            $details[] = "{$deleted} token(s) anteriores de {$bucket} eliminados.";
        }

        return [
            'settings' => [
                'provider'          => 'r2',
                'bucket'            => $bucket,
                'endpoint'          => "https://{$account}.r2.cloudflarestorage.com",
                'region'            => null,
                'access_key_id'     => $id,
                'secret_access_key' => hash('sha256', $value),
            ],
            'details' => $details,
        ];
    }

    /** @return array{0:string,1:string} [ruta de creación de tokens, id del permission group] */
    private function permissionGroup(string $account): array
    {
        foreach (['/user/tokens', "/accounts/{$account}/tokens"] as $base) {
            $res = $this->http()->get(self::API.$base.'/permission_groups');
            if (! $res->successful()) {
                continue;
            }
            foreach ($res->json('result') ?? [] as $g) {
                if (($g['name'] ?? null) === self::OBJECT_WRITE_GROUP) {
                    return [$base, $g['id']];
                }
            }
        }

        throw new ProvisioningException('El token no puede crear tokens nuevos: agrégale el permiso "API Tokens: Edit" (o "Account API Tokens: Edit").');
    }

    /** Borra los tokens con ese nombre salvo el recién creado. Best-effort: devuelve cuántos borró. */
    private function deleteOldTokens(string $base, string $name, string $keepId): int
    {
        $res = $this->http()->get(self::API.$base, ['per_page' => 50]);
        if (! $res->successful()) {
            return 0;
        }

        $deleted = 0;
        foreach ($res->json('result') ?? [] as $token) {
            if (($token['name'] ?? null) !== $name || ($token['id'] ?? null) === $keepId || empty($token['id'])) {
                continue;
            }
            if ($this->http()->delete(self::API.$base.'/'.$token['id'])->successful()) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function onlyAccount(): string
    {
        $res = $this->http()->get(self::API.'/accounts');
        $this->ok($res, 'listar las cuentas');
        $accounts = $res->json('result') ?? [];
        if (count($accounts) !== 1) {
            throw new ProvisioningException(count($accounts) === 0
                ? 'El token no tiene acceso a ninguna cuenta de Cloudflare.'
                : 'El token ve varias cuentas: indica el Account ID.');
        }

        return $accounts[0]['id'];
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)->acceptJson()->timeout(30);
    }

    private function hasError(Response $res, int $code): bool
    {
        return collect($res->json('errors') ?? [])->contains(fn ($e) => (int) ($e['code'] ?? 0) === $code);
    }

    private function ok(Response $res, string $step): void
    {
        if ($res->successful() && $res->json('success') !== false) {
            return;
        }
        $msg = $res->json('errors.0.message') ?? ('HTTP '.$res->status());
        throw new ProvisioningException("No se pudo {$step}: {$msg}");
    }
}
