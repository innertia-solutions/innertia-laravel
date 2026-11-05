<?php

namespace Innertia\Storage\Provisioning;

/**
 * Aprovisiona un bucket + una credencial S3 de mínimo privilegio usando una
 * credencial de ADMINISTRACIÓN que se usa una sola vez y NO se persiste.
 *
 * Devuelve los settings del disco cloud (mismas claves que StorageManager::CLOUD_FIELDS)
 * para que el producto los guarde por su vía normal (secretos encriptados).
 */
abstract class StorageProvisioner
{
    /** Pausa entre reintentos (ms). Los tests la ponen en 0. */
    public static int $retryDelayMs = 2000;

    /**
     * @param  array  $input  credencial admin + bucket + opciones del proveedor
     * @return array{settings: array<string, ?string>, details: string[]}
     */
    abstract public function provision(array $input): array;

    protected function assertBucketName(?string $bucket): string
    {
        $bucket = strtolower(trim((string) $bucket));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket)) {
            throw new ProvisioningException('Nombre de bucket inválido: 3–63 caracteres, minúsculas, números y guiones (sin empezar ni terminar en guion).');
        }

        return $bucket;
    }

    /**
     * Reintenta $fn mientras $retryable($e) sea true (propagación eventual de IAM/
     * credenciales recién creadas en los proveedores).
     */
    protected function retry(callable $fn, callable $retryable, int $attempts = 5): mixed
    {
        for ($i = 1; ; $i++) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                if ($i >= $attempts || ! $retryable($e)) {
                    throw $e;
                }
                if (static::$retryDelayMs > 0) {
                    usleep(static::$retryDelayMs * 1000);
                }
            }
        }
    }
}
