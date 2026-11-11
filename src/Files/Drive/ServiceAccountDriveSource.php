<?php

namespace Innertia\Files\Drive;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Innertia\Facades\Settings;
use Innertia\Files\Drive\Exceptions\DriveException;
use Innertia\Files\Drive\Exceptions\DriveExportTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveFileTooLargeException;
use Innertia\Files\Drive\Exceptions\DriveItemNotFoundException;
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
            $this->ensureOk($res, 'listar la carpeta');

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

        for ($i = 0, $parent = $item->parents[0] ?? null; $parent !== null && $i < 20; $i++) {
            try {
                $node = $this->metadata($parent);
            } catch (DriveException) {
                break; // padre sin acceso: la ruta queda parcial
            }
            array_unshift($path, ['id' => $node->id, 'name' => $node->name]);
            $parent = $node->parents[0] ?? null;
        }

        if ($path === []) {
            return [];
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
        $this->ensureOk($res, 'crear la carpeta');

        return DriveItem::fromApi($res->json());
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->timeout(60)
            ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);
    }

    /**
     * Único punto que ejecuta requests a Google: un error de conexión (agotados los
     * reintentos) sale como DriveException, igual que cualquier otra falla de la API.
     */
    private function send(\Closure $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new DriveException('No se pudo conectar con Google Drive: ' . $e->getMessage(), 'connection');
        }
    }

    private function streamToTemp(Response $res, int $maxBytes): string
    {
        $tmp = TempFile::create();
        $out = fopen($tmp, 'wb');
        $body = $res->toPsrResponse()->getBody();
        $written = 0;

        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK);
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new DriveFileTooLargeException($maxBytes);
                }
                fwrite($out, $chunk);
            }
        } catch (\Throwable $e) {
            fclose($out);
            TempFile::delete($tmp);
            throw $e;
        }

        fclose($out);

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

        $message = $res->json('error.message') ?: ('HTTP ' . $res->status());

        throw new DriveException("Google Drive no permitió {$what}: {$message}", $reason, $res->status());
    }

    private function token(): string
    {
        $sa = $this->serviceAccount();
        $subject = $this->subject();
        $key = 'innertia.files.drive.token.' . md5($sa['client_email'] . '|' . $subject);

        return Cache::remember($key, 3300, function () use ($sa, $subject) {
            $tokenUri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $claims = ['iss' => $sa['client_email'], 'scope' => self::SCOPE, 'aud' => $tokenUri, 'iat' => $now, 'exp' => $now + 3600];
            if ($subject !== '') {
                $claims['sub'] = $subject;
            }

            $res = $this->send(fn () => Http::asForm()->timeout(20)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => JWT::encode($claims, $sa['private_key'], 'RS256'),
            ]));

            if ($res->failed() || ! $res->json('access_token')) {
                $detail = $res->json('error_description') ?: $res->json('error') ?: ('HTTP ' . $res->status());
                throw new DriveException("Autenticación con Google falló: {$detail}", 'auth', $res->status());
            }

            return (string) $res->json('access_token');
        });
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
