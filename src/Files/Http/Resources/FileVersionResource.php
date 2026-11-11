<?php

namespace Innertia\Files\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;
use Innertia\Files\Models\File;
use Innertia\Files\Models\FileVersion;

/**
 * Una entrada del historial. Envuelve tanto un FileVersion (superada) como el
 * File (vigente) con la misma forma, vía fromCurrent().
 */
class FileVersionResource extends JsonResource
{
    /**
     * $fileTrashed: el archivo dueño está en la papelera → download_url null
     * (el serving responde 404 para archivos en papelera).
     */
    public function __construct($resource, public readonly bool $fileTrashed = false)
    {
        parent::__construct($resource);
    }

    public static function fromCurrent(File $file): self
    {
        return new self([
            'file_id'               => $file->id,
            'version'               => (int) $file->version,
            'original_name'         => $file->original_name,
            'mime_type'             => $file->mime_type,
            'extension'             => $file->extension,
            'size'                  => (int) $file->size,
            'source'                => $file->version_source,
            'restored_from_version' => $file->restored_from_version,
            'created_by'            => $file->version_created_by,
            'created_at'            => $file->version_created_at ?? $file->created_at,
            'superseded_at'         => null,
            'is_current'            => true,
        ], $file->trashed());
    }

    public function toArray($request): array
    {
        $row = $this->resource instanceof FileVersion
            ? array_merge($this->resource->only([
                'file_id', 'version', 'original_name', 'mime_type', 'extension', 'size', 'source',
                'restored_from_version', 'created_by', 'created_at', 'superseded_at',
            ]), ['is_current' => false])
            : $this->resource;

        return [
            'version'               => (int) $row['version'],
            'is_current'            => (bool) $row['is_current'],
            'original_name'         => $row['original_name'],
            'mime_type'             => $row['mime_type'],
            'extension'             => $row['extension'],
            'size'                  => (int) $row['size'],
            'source'                => $row['source'],
            'restored_from_version' => $row['restored_from_version'],
            'created_by'            => $row['created_by'],
            'created_at'            => $row['created_at']?->toIso8601String(),
            'superseded_at'         => $row['superseded_at']?->toIso8601String(),
            'download_url'          => $this->fileTrashed ? null : $this->signed($row['file_id'], (int) $row['version']),
        ];
    }

    private function signed(string $fileId, int $version): ?string
    {
        try {
            return URL::temporarySignedRoute(
                'innertia.files.versions.download',
                now()->addMinutes((int) config('innertia.files.url_ttl', 15)),
                ['id' => $fileId, 'version' => $version],
                absolute: false,
            );
        } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException) {
            return null;
        }
    }
}
