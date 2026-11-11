<?php

namespace Innertia\Files\Drive;

/** Un archivo o carpeta de Google Drive (subset de campos que usa la lib). */
final class DriveItem
{
    public const FOLDER_MIME   = 'application/vnd.google-apps.folder';
    public const SHORTCUT_MIME = 'application/vnd.google-apps.shortcut';

    /** Campos pedidos a la API (files.get / files.list). */
    public const API_FIELDS = 'id,name,mimeType,size,md5Checksum,modifiedTime,version,headRevisionId,webViewLink,driveId,parents';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $mime,
        public readonly ?int $size = null,
        public readonly ?string $md5 = null,
        public readonly ?string $modifiedTime = null,
        public readonly ?string $version = null,
        public readonly ?string $webUrl = null,
        public readonly ?string $driveId = null,
        /** @var list<string> */
        public readonly array $parents = [],
        public readonly ?string $headRevisionId = null,
    ) {}

    public static function fromApi(array $f): self
    {
        return new self(
            id:           (string) $f['id'],
            name:         (string) ($f['name'] ?? ''),
            mime:         (string) ($f['mimeType'] ?? 'application/octet-stream'),
            size:         isset($f['size']) ? (int) $f['size'] : null,
            md5:          $f['md5Checksum'] ?? null,
            modifiedTime: $f['modifiedTime'] ?? null,
            version:      isset($f['version']) ? (string) $f['version'] : null,
            webUrl:       $f['webViewLink'] ?? null,
            driveId:      $f['driveId'] ?? null,
            parents:      array_values($f['parents'] ?? []),
            headRevisionId: $f['headRevisionId'] ?? null,
        );
    }

    public function isFolder(): bool
    {
        return $this->mime === self::FOLDER_MIME;
    }

    public function isShortcut(): bool
    {
        return $this->mime === self::SHORTCUT_MIME;
    }

    /** Documento nativo de Google (Docs/Sheets/Slides/…): no tiene bytes, se exporta. */
    public function isNative(): bool
    {
        return str_starts_with($this->mime, 'application/vnd.google-apps.') && ! $this->isFolder() && ! $this->isShortcut();
    }

    /**
     * Identifica el contenido: md5 (o headRevisionId) para binarios, `modifiedTime` para nativos.
     *
     * No se usa `version` de Drive como señal principal: sube también con cambios de
     * metadatos (compartir, mover, renombrar), y los PDF/DOCX exportados de un nativo no son
     * byte-deterministas, así que cada cambio de `version` terminaría en una versión espuria.
     * `version` queda solo como último recurso para nativos sin modifiedTime.
     */
    public function revision(): ?string
    {
        return $this->isNative()
            ? ($this->modifiedTime ?? $this->version)
            : ($this->md5 ?? $this->headRevisionId ?? $this->modifiedTime);
    }

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'mime_type'     => $this->mime,
            'size'          => $this->size,
            'modified_time' => $this->modifiedTime,
            'web_url'       => $this->webUrl,
            'drive_id'      => $this->driveId,
            'is_folder'     => $this->isFolder(),
            'is_native'     => $this->isNative(),
        ];
    }
}
