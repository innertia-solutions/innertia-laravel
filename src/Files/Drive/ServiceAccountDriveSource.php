<?php

namespace Innertia\Files\Drive;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Innertia\Facades\Settings;
use Innertia\Files\Drive\Exceptions\DriveException;
use Innertia\Files\Drive\Exceptions\DriveExportTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveFileTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveItemNotFoundException;
use Innertia\Files\Drive\Exceptions\DriveItemNotImportableException;
use Innertia\Files\Drive\Exceptions\DriveNotConfiguredException;
use Innertia\Files\Support\TempFile;

/**
 * Drive con la cuenta de servicio de Google Workspace (domain-wide delegation, scope `drive`),
 * impersonando al usuario de `subject_setting` o, si está vacío, a workspace.google.admin_email.
 * Lo que se ve es exactamente donde ese usuario puede leer y escribir.
 */
class ServiceAccountDriveSource implements DriveSource
{
    private const SCOPE = 'https://www.googleapis.com/auth/drive';
    private const API   = 'https://www.googleapis.com/drive/v3';
    private const CHUNK = 1048576;

    /** Espera (ms) antes de cada reintento ante límites de cuota o fallas transitorias de Google. */
    private const BACKOFF_MS = [500, 1500, 4000];

    private const RATE_LIMIT_REASONS = ['rateLimitExceeded', 'userRateLimitExceeded'];

    /** 403 por los que el dueño (o Google) impide bajar/exportar el archivo. */
    private const DOWNLOAD_DISABLED_REASONS = ['cannotDownloadFile', 'cannotExportFile', 'fileNotDownloadable', 'cannotDownloadAbusiveFile'];

    /**
     * ¿Hay cuenta de servicio cargada? Se usa al serializar cada FileResource (can_refresh),
     * así que nunca debe lanzar: sin Settings disponible (instalación mínima, tests) = false.
     */
    public static function hasCredentials(): bool
    {
        try {
            return filled(Settings::get('workspace.google.service_account_json'));
        } catch (\Throwable) {
            return false;
        }
    }

    public function listChildren(string $folderId = 'root', ?string $driveId = null, bool $foldersOnly = false): array
    {
        $q = sprintf("'%s' in parents and trashed=false", self::escape($folderId));
        if ($foldersOnly) {
            $q .= " and mimeType='" . DriveItem::FOLDER_MIME . "'";
        }

        $query = [
            'q'                         => $q,
            'fields'                    => 'nextPageToken, files(' . DriveItem::API_FIELDS . ')',
            'orderBy'                   => 'folder,name_natural',
            'pageSize'                  => 1000,
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
        ];
        if ($driveId) {
            $query += ['corpora' => 'drive', 'driveId' => $driveId];
        }

        $max = (int) config('innertia.files.drive.list_max', 5000);
        $items = [];
        $page = null;

        do {
            $res = $this->send(fn () => $this->http()->get(self::API . '/files', $query + ($page ? ['pageToken' => $page] : [])));
            $this->ensureOk($res, 'listar la carpeta', $folderId);

            foreach ($res->json('files', []) as $f) {
                $items[] = DriveItem::fromApi($f);
            }

            if (count($items) > $max) {
                throw new DriveException("La carpeta tiene más de {$max} elementos; elige una subcarpeta.", 'tooManyChildren');
            }

            $page = $res->json('nextPageToken');
        } while ($page);

        return $items;
    }

    public function sharedDrives(): array
    {
        $drives = [];
        $page = null;

        do {
            $res = $this->send(fn () => $this->http()->get(self::API . '/drives', ['pageSize' => 100, 'fields' => 'nextPageToken, drives(id, name)'] + ($page ? ['pageToken' => $page] : [])));
            $this->ensureOk($res, 'listar las unidades compartidas');

            foreach ($res->json('drives', []) as $d) {
                $drives[] = ['id' => (string) $d['id'], 'name' => (string) $d['name']];
            }
            $page = $res->json('nextPageToken');
        } while ($page);

        return $drives;
    }

    public function metadata(string $id): DriveItem
    {
        $res = $this->send(fn () => $this->http()->get(self::API . '/files/' . rawurlencode($id), ['fields' => DriveItem::API_FIELDS, 'supportsAllDrives' => 'true']));
        $this->ensureOk($res, 'leer el elemento', $id);

        return DriveItem::fromApi($res->json());
    }

    public function path(string $id): array
    {
        $item = $this->metadata($id);
        $path = [];
        $reachedTop = false;
        $parent = $item->parents[0] ?? null;

        for ($i = 0; $i < 20; $i++) {
            if ($parent === null) {
                $reachedTop = true;
                break;
            }
            try {
                $node = $this->metadata($parent);
            } catch (DriveItemNotFoundException) {
                break; // padre inexistente o sin acceso: la ruta queda parcial
            } catch (DriveException $e) {
                if (! self::isPermissionDenied($e)) {
                    throw $e;
                }
                break;
            }
            array_unshift($path, ['id' => $node->id, 'name' => $node->name]);
            $parent = $node->parents[0] ?? null;
        }
        $reachedTop = $reachedTop || $parent === null;

        // Solo se nombra la raíz si de verdad se llegó a ella; una ruta cortada se devuelve tal cual.
        if ($path === [] || ! $reachedTop) {
            return $path;
        }

        if ($item->driveId !== null && $path[0]['id'] === $item->driveId) {
            try {
                $drive = $this->send(fn () => $this->http()->get(self::API . '/drives/' . rawurlencode($item->driveId), ['fields' => 'name']));
                $path[0]['name'] = $drive->successful() ? (string) $drive->json('name', $path[0]['name']) : $path[0]['name'];
            } catch (DriveException) {
                // sin conexión para el nombre de la unidad: se conserva el nombre de la raíz
            }
        } elseif ($item->driveId === null) {
            $path[0] = ['id' => 'root', 'name' => 'Mi unidad'];
        }

        return $path;
    }

    public function download(string $id, int $maxBytes): string
    {
        $res = $this->send(fn () => $this->http()->withOptions(['stream' => true])
            ->get(self::API . '/files/' . rawurlencode($id), ['alt' => 'media', 'supportsAllDrives' => 'true']));
        $this->ensureOk($res, 'descargar el archivo', $id);

        return $this->streamToTemp($res, $maxBytes);
    }

    public function export(string $id, string $mime): string
    {
        $res = $this->send(fn () => $this->http()->withOptions(['stream' => true])
            ->get(self::API . '/files/' . rawurlencode($id) . '/export', ['mimeType' => $mime]));
        $this->ensureOk($res, 'exportar el documento', $id);

        return $this->streamToTemp($res, (int) config('innertia.files.drive.max_file_size', 200 * 1024 * 1024));
    }

    public function createFolder(string $name, string $parentId): DriveItem
    {
        $res = $this->send(fn () => $this->http()->post(self::API . '/files?supportsAllDrives=true&fields=' . urlencode(DriveItem::API_FIELDS), [
            'name'     => $name,
            'mimeType' => DriveItem::FOLDER_MIME,
            'parents'  => [$parentId],
        ]));
        $this->ensureOk($res, 'crear la carpeta', $parentId);

        return DriveItem::fromApi($res->json());
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->timeout(60);
    }

    /**
     * Único punto que ejecuta requests a Google. El closure debe construir el request
     * completo (incluido `$this->http()`), porque se re-ejecuta en cada intento:
     * - error de conexión, 429, 5xx o 403 de cuota → reintenta con backoff (BACKOFF_MS);
     * - 401 en la API → olvida el token cacheado y reintenta UNA vez con uno nuevo.
     * Agotados los reintentos, un error de conexión sale como DriveException('connection');
     * cualquier otra respuesta se devuelve para que ensureOk la traduzca.
     *
     * @param  bool  $tokenRequest  pedido de token: solo reintenta conexión/5xx, sin refresco por 401.
     */
    private function send(\Closure $request, bool $tokenRequest = false): Response
    {
        $attempt = 0;
        $tokenRefreshed = false;

        while (true) {
            try {
                $res = $request();
            } catch (ConnectionException $e) {
                if ($attempt < count(self::BACKOFF_MS)) {
                    Sleep::for(self::BACKOFF_MS[$attempt++])->milliseconds();
                    continue;
                }
                throw new DriveException('No se pudo conectar con Google Drive: ' . $e->getMessage(), 'connection');
            }

            if (! $tokenRequest && $res->status() === 401 && ! $tokenRefreshed) {
                $tokenRefreshed = true;
                Cache::forget($this->tokenKey());
                continue;
            }

            if ($attempt < count(self::BACKOFF_MS) && $this->isTransient($res, $tokenRequest)) {
                Sleep::for(self::BACKOFF_MS[$attempt++])->milliseconds();
                continue;
            }

            return $res;
        }
    }

    private function isTransient(Response $res, bool $tokenRequest): bool
    {
        $status = $res->status();

        if (in_array($status, [500, 502, 503, 504], true)) {
            return true;
        }
        if ($tokenRequest) {
            return false;
        }

        return $status === 429
            || ($status === 403 && in_array($res->json('error.errors.0.reason'), self::RATE_LIMIT_REASONS, true));
    }

    /** 403 por permisos (no por cuota): el elemento existe pero este usuario no lo ve. */
    private static function isPermissionDenied(DriveException $e): bool
    {
        return $e->status === 403 && ! in_array($e->reason, self::RATE_LIMIT_REASONS, true);
    }

    private function streamToTemp(Response $res, int $maxBytes): string
    {
        $body = $res->toPsrResponse()->getBody();
        $tmp = null;
        $out = null;
        $written = 0;

        try {
            $tmp = TempFile::create();
            $out = @fopen($tmp, 'wb');
            if ($out === false) {
                $out = null;
                throw new DriveException('No se pudo crear el archivo temporal.', 'tempWrite');
            }

            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK);
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new DriveFileTooLargeException($maxBytes);
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new DriveException('No se pudo escribir el archivo temporal.', 'tempWrite');
                }
            }
        } catch (\Throwable $e) {
            $body->close();
            if ($out !== null) {
                fclose($out);
            }
            TempFile::delete($tmp);
            throw $e;
        }

        fclose($out);
        $body->close();

        return $tmp;
    }

    private function ensureOk(Response $res, string $what, ?string $id = null): void
    {
        if ($res->successful()) {
            return;
        }

        $reason = $res->json('error.errors.0.reason');

        if ($res->status() === 404 && $id !== null) {
            throw DriveItemNotFoundException::for($id);
        }
        if ($reason === 'exportSizeLimitExceeded') {
            throw new DriveExportTooLargeException();
        }
        if ($res->status() === 403 && in_array($reason, self::DOWNLOAD_DISABLED_REASONS, true)) {
            throw DriveItemNotImportableException::downloadDisabled($reason);
        }

        $message = $res->json('error.message') ?: ('HTTP ' . $res->status());

        throw new DriveException("Google Drive no permitió {$what}: {$message}", $reason, $res->status());
    }

    /**
     * Clave de caché del token: cuenta de servicio + llave + usuario impersonado.
     * Incluye private_key_id (o md5 de la llave) para que rotar la llave no reuse un token viejo.
     */
    private function tokenKey(): string
    {
        $sa = $this->serviceAccount();
        $keyId = $sa['private_key_id'] ?? md5($sa['private_key']);

        return 'innertia.files.drive.token.' . md5($sa['client_email'] . '|' . $keyId . '|' . $this->subject());
    }

    private function token(): string
    {
        $key = $this->tokenKey();

        if (is_string($cached = Cache::get($key)) && $cached !== '') {
            return $cached;
        }

        $sa = $this->serviceAccount();
        $subject = $this->subject();

        $tokenUri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $claims = ['iss' => $sa['client_email'], 'scope' => self::SCOPE, 'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600];
        if ($subject !== '') {
            $claims['sub'] = $subject;
        }

        $res = $this->send(fn () => Http::asForm()->timeout(20)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => JWT::encode($claims, $sa['private_key'], 'RS256'),
        ]), tokenRequest: true);

        if ($res->failed() || ! $res->json('access_token')) {
            $detail = $res->json('error_description') ?: $res->json('error') ?: ('HTTP ' . $res->status());
            throw new DriveException("Autenticación con Google falló: {$detail}", 'auth', $res->status());
        }

        $token = (string) $res->json('access_token');
        $expiresIn = $res->json('expires_in');
        Cache::put($key, $token, is_numeric($expiresIn) ? max(60, (int) $expiresIn - 300) : 3300);

        return $token;
    }

    private function subject(): string
    {
        $setting = (string) config('innertia.files.drive.subject_setting', 'integrations.google.drive_subject');

        return (string) (Settings::get($setting) ?: Settings::get('workspace.google.admin_email') ?: '');
    }

    /** @return array{client_email: string, private_key: string, token_uri?: string} */
    private function serviceAccount(): array
    {
        $sa = json_decode((string) Settings::get('workspace.google.service_account_json'), true);

        if (! is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new DriveNotConfiguredException();
        }

        return $sa;
    }

    /** Escapa un id para el lenguaje de consulta de Drive (`q`). */
    private static function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
