<?php

namespace Innertia\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage as LaravelStorage;
use Illuminate\Support\Str;
use Innertia\Facades\Settings;

/**
 * Resuelve discos de almacenamiento desde la capa de Settings (integraciones) en vez
 * de env. Dos mecanismos, ambos opt-in:
 *
 *  1. `innertia.storage.from_settings` — mapa disco => [settingsKey => configKey]
 *     (legado, p. ej. theAdmin con r2). Si faltan keys deja la config env intacta.
 *
 *  2. `innertia.storage.cloud` — disco "cloud" MULTI-PROVEEDOR configurable desde
 *     la UI (proveedor r2 | s3 | gcs + bucket + credenciales). Los tres hablan el
 *     protocolo S3 (GCS vía su API XML interoperable con claves HMAC), así que se
 *     usa el driver s3 de Laravel con distinto endpoint/región. Si está completo y
 *     `as_default`, pasa a ser filesystems.default: todo lo nuevo se sube ahí y lo
 *     antiguo se sigue leyendo de su disco original (files.disk lo recuerda).
 */
class StorageManager
{
    public const PROVIDERS = [
        'r2'  => 'Cloudflare R2',
        's3'  => 'Amazon S3',
        'gcs' => 'Google Cloud Storage',
    ];

    public const GCS_ENDPOINT = 'https://storage.googleapis.com';

    /** Driver de disco: s3 de Laravel + middleware que quita las ACL (ver registerGcsDriver). */
    public const GCS_DRIVER = 'innertia-gcs';

    /** Campos del disco cloud en Settings (relativos al prefijo). */
    public const CLOUD_FIELDS = ['provider', 'bucket', 'region', 'endpoint', 'access_key_id', 'secret_access_key', 'public_url'];

    public function hydrate(): void
    {
        $map = (array) config('innertia.storage.from_settings', []);
        foreach ($map as $disk => $fields) {
            $values = [];
            $complete = true;
            foreach ($fields as $settingsKey => $configKey) {
                $val = $this->setting($settingsKey);
                if (! filled($val)) {
                    $complete = false;
                    break;
                }
                $values[$configKey] = $val;
            }
            if ($complete && $values) {
                config(['filesystems.disks.'.$disk => array_merge(
                    (array) config('filesystems.disks.'.$disk, []),
                    $values,
                )]);
            }
        }

        $this->hydrateCloud();
    }

    // ── Disco cloud multi-proveedor ─────────────────────────────────────────────

    public function cloudEnabled(): bool
    {
        return (bool) config('innertia.storage.cloud.enabled', false);
    }

    public function cloudDisk(): string
    {
        return (string) config('innertia.storage.cloud.disk', 'cloud');
    }

    /** Valores guardados del disco cloud (secretos ya desencriptados por Settings). */
    public function cloudSettings(): array
    {
        $prefix = rtrim((string) config('innertia.storage.cloud.settings_prefix', 'integrations.storage'), '.');
        $out = [];
        foreach (self::CLOUD_FIELDS as $field) {
            $out[$field] = $this->setting("{$prefix}.{$field}");
        }

        return $out;
    }

    /**
     * Config de disco Laravel para los settings dados, o null si están incompletos.
     * Pura (sin IO): se usa para hidratar y para validar antes de guardar.
     */
    public function buildCloudConfig(array $s): ?array
    {
        $provider = $s['provider'] ?? null;
        if (! isset(self::PROVIDERS[$provider])) {
            return null;
        }
        foreach (['bucket', 'access_key_id', 'secret_access_key'] as $required) {
            if (! filled($s[$required] ?? null)) {
                return null;
            }
        }

        $endpoint = filled($s['endpoint'] ?? null) ? rtrim((string) $s['endpoint'], '/') : null;
        $region = filled($s['region'] ?? null) ? (string) $s['region'] : null;

        [$endpoint, $region, $pathStyle] = match ($provider) {
            // R2: endpoint https://<account_id>.r2.cloudflarestorage.com obligatorio.
            'r2'  => [$endpoint, 'auto', true],
            // S3: región obligatoria; endpoint solo para compatibles (MinIO, etc.).
            's3'  => [$endpoint, $region, $endpoint !== null],
            // GCS: API XML interoperable (claves HMAC).
            'gcs' => [$endpoint ?? self::GCS_ENDPOINT, $region ?? 'auto', true],
        };

        if ($provider === 'r2' && ! $endpoint) {
            return null;
        }
        if ($provider === 's3' && ! $region) {
            return null;
        }

        // GCS (API XML) con acceso uniforme (como lo crea el aprovisionador) responde
        // InvalidArgument a dos cosas que el driver s3 manda por defecto:
        //  - el checksum CRC32 que el SDK de AWS agrega desde 3.337 → solo cuando se exija;
        //  - el header x-amz-acl (sale del `visibility` del disco) → sin visibility: los
        //    objetos heredan el acceso privado del bucket. retain_visibility=false para que
        //    copy() no lea ACLs (GetObjectAcl tampoco existe con acceso uniforme).
        //  Las ACL las quita el driver `innertia-gcs` (Flysystem siempre manda una).
        $gcs = $provider === 'gcs';
        $checksums = $gcs ? 'when_required' : null;

        return array_filter([
            'request_checksum_calculation' => $checksums,
            'response_checksum_validation' => $checksums,
            'driver'                  => $gcs ? self::GCS_DRIVER : 's3',
            'key'                     => (string) $s['access_key_id'],
            'secret'                  => (string) $s['secret_access_key'],
            'region'                  => $region,
            'bucket'                  => (string) $s['bucket'],
            'endpoint'                => $endpoint,
            'url'                     => filled($s['public_url'] ?? null) ? rtrim((string) $s['public_url'], '/') : null,
            'use_path_style_endpoint' => $pathStyle,
            'visibility'              => $gcs ? null : 'private',
            'retain_visibility'       => $gcs ? false : null,
            // Fallar fuerte: con throw=false un put fallido devuelve false y el
            // archivo quedaba registrado sin contenido.
            'throw'                   => true,
            'report'                  => false,
        ], fn ($v) => $v !== null);
    }

    /** Registra el disco cloud (y lo deja como default) si está completo en Settings. */
    public function hydrateCloud(): void
    {
        if (! $this->cloudEnabled()) {
            return;
        }

        $config = $this->buildCloudConfig($this->cloudSettings());
        if (! $config) {
            return;
        }

        $disk = $this->cloudDisk();
        // Solo si cambió: los workers re-hidratan antes de cada job y no deben
        // reconstruir el cliente (ni descartar un disco ya resuelto) sin motivo.
        if (config('filesystems.disks.'.$disk) !== $config) {
            config(['filesystems.disks.'.$disk => $config]);
            LaravelStorage::forgetDisk($disk); // credenciales nuevas → cliente nuevo
        }

        if (config('innertia.storage.cloud.as_default', true)) {
            config(['filesystems.default' => $disk]);
        }
    }

    public function cloudConfigured(): bool
    {
        return $this->cloudEnabled() && $this->buildCloudConfig($this->cloudSettings()) !== null;
    }

    /**
     * Prueba en vivo: escribe, lee y borra un objeto en el bucket.
     *
     * @return array{ok:bool, message:string, details?:array}
     */
    public function probe(?array $settings = null): array
    {
        $settings ??= $this->cloudSettings();
        $config = $this->buildCloudConfig($settings);
        if (! $config) {
            return ['ok' => false, 'message' => $this->missingMessage($settings)];
        }

        $details = array_values(array_filter([
            'Proveedor: '.self::PROVIDERS[$settings['provider']],
            'Bucket: '.$config['bucket'],
            isset($config['endpoint']) ? 'Endpoint: '.$config['endpoint'] : null,
        ]));

        $path = '.innertia-probe/'.Str::uuid()->toString().'.txt';
        try {
            $fs = LaravelStorage::build($config);
            $fs->put($path, 'ok');
            $read = $fs->get($path);
            $fs->delete($path);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'No se pudo escribir en el bucket: '.$this->cleanError($e), 'details' => $details];
        }

        if ($read !== 'ok') {
            return ['ok' => false, 'message' => 'El bucket aceptó la escritura pero no devolvió el contenido.', 'details' => $details];
        }

        return ['ok' => true, 'message' => 'Bucket accesible: escritura, lectura y borrado correctos.', 'details' => $details];
    }

    private function missingMessage(array $s): string
    {
        $provider = $s['provider'] ?? null;
        if (! isset(self::PROVIDERS[$provider])) {
            return 'Elige un proveedor (r2, s3 o gcs).';
        }
        $missing = [];
        foreach (['bucket' => 'bucket', 'access_key_id' => 'Access Key ID', 'secret_access_key' => 'Secret Access Key'] as $k => $label) {
            if (! filled($s[$k] ?? null)) {
                $missing[] = $label;
            }
        }
        if ($provider === 'r2' && ! filled($s['endpoint'] ?? null)) {
            $missing[] = 'endpoint (https://<account_id>.r2.cloudflarestorage.com)';
        }
        if ($provider === 's3' && ! filled($s['region'] ?? null)) {
            $missing[] = 'región';
        }

        return 'Falta: '.implode(', ', $missing).'.';
    }

    /** Mensaje de error legible sin volcar la respuesta XML completa del proveedor. */
    private function cleanError(\Throwable $e): string
    {
        $prev = $e->getPrevious() ?? $e;
        if (method_exists($prev, 'getAwsErrorCode') && $prev->getAwsErrorCode()) {
            return $prev->getAwsErrorCode().($prev->getAwsErrorMessage() ? ' — '.$prev->getAwsErrorMessage() : '');
        }

        return Str::limit(strtok($prev->getMessage(), "\n") ?: $e->getMessage(), 200);
    }

    // ── Aprovisionamiento automático ────────────────────────────────────────────

    /** Proveedores que pueden crear bucket + credencial solos desde una credencial admin. */
    public const PROVISIONERS = [
        'gcs' => Provisioning\GcsProvisioner::class,
        'r2'  => Provisioning\R2Provisioner::class,
    ];

    /**
     * Crea bucket + credencial de mínimo privilegio con una credencial ADMIN de un solo
     * uso (no se persiste). Devuelve los settings del disco cloud para que el producto
     * los guarde por su vía (secretos encriptados) y luego valide con probe().
     *
     * @return array{settings: array<string, ?string>, details: string[]}
     *
     * @throws Provisioning\ProvisioningException
     */
    public function provision(string $provider, array $input): array
    {
        $class = self::PROVISIONERS[$provider]
            ?? throw new Provisioning\ProvisioningException('El proveedor "'.$provider.'" no admite configuración automática.');

        return app($class)->provision($input);
    }

    /**
     * probe() con reintentos: las credenciales recién emitidas (HMAC de GCS, tokens de
     * R2) tardan unos segundos en propagarse.
     */
    public function probeWithRetry(?array $settings = null, int $attempts = 6): array
    {
        $delay = Provisioning\StorageProvisioner::$retryDelayMs;
        for ($i = 1; ; $i++) {
            $result = $this->probe($settings);
            if ($result['ok'] || $i >= $attempts) {
                return $result;
            }
            if ($delay > 0) {
                usleep($delay * 1000);
            }
        }
    }

    /**
     * Registra el driver `innertia-gcs`: el disco s3 de Laravel con un middleware en el
     * cliente del SDK que elimina el parámetro ACL de cada comando. Flysystem SIEMPRE
     * manda `x-amz-acl` al subir (por defecto "private") y un bucket GCS con acceso
     * uniforme responde InvalidArgument; sin ACL los objetos heredan el acceso del bucket.
     */
    public static function registerGcsDriver(): void
    {
        LaravelStorage::extend(self::GCS_DRIVER, function ($app, array $config) {
            $config['driver'] = 's3';
            $disk = $app['filesystem']->createS3Driver($config);
            $disk->getClient()->getHandlerList()->appendInit(
                \Aws\Middleware::mapCommand(function (\Aws\CommandInterface $command) {
                    unset($command['ACL']);

                    return $command;
                }),
                'innertia-gcs-no-acl',
            );

            return $disk;
        });
    }

    // ── Utilidades ──────────────────────────────────────────────────────────────

    public function configured(string $disk): bool
    {
        $cfg = (array) config('filesystems.disks.'.$disk, []);

        // Configurado si tiene bucket+key (por Settings recién hidratadas o por env).
        return filled($cfg['bucket'] ?? null) && filled($cfg['key'] ?? null);
    }

    public function activeDisk(): string
    {
        if ($this->cloudEnabled() && $this->configured($this->cloudDisk())) {
            return $this->cloudDisk();
        }

        $cloud = config('innertia.storage.cloud_disk', 'r2');

        return $this->configured($cloud) ? $cloud : config('innertia.storage.fallback_disk', 'public');
    }

    /**
     * Ruta local legible de un archivo, sea cual sea su disco. Discos locales → su
     * ruta real; remotos (s3/r2/gcs) → copia a un temporal (borrado al terminar el
     * proceso). Para librerías que necesitan un path (lectores xlsx/pdf, etc.).
     */
    public function localPath(string $disk, string $path): string
    {
        $fs = LaravelStorage::disk($disk);
        if ((config("filesystems.disks.{$disk}.driver") ?? 'local') === 'local') {
            return $fs->path($path);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'innertia_').'.'.pathinfo($path, PATHINFO_EXTENSION);
        $in = $fs->readStream($path);
        $out = fopen($tmp, 'w');
        stream_copy_to_stream($in, $out);
        fclose($out);
        if (is_resource($in)) {
            fclose($in);
        }
        register_shutdown_function(fn () => @unlink($tmp));

        return $tmp;
    }

    public function disk(): Filesystem
    {
        return LaravelStorage::disk($this->activeDisk());
    }

    private function setting(string $key): mixed
    {
        try {
            return Settings::get($key);
        } catch (QueryException) {
            // BD/tabla settings no disponible (migrate, boot pre-migración) → sin valor.
            return null;
        }
    }
}
