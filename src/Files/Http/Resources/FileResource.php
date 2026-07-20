<?php

namespace Innertia\Files\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'original_name'  => $this->original_name,
            'mime_type'      => $this->mime_type,
            'extension'      => $this->extension,
            'size'           => $this->size,
            'visibility'     => $this->visibility,
            'directory_id'   => $this->directory_id ?? null,
            'owner_type'     => $this->owner_type,
            'owner_id'       => $this->owner_id,
            'trash_group_id' => $this->trash_group_id,
            'deleted_at'     => $this->deleted_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
            'created_by'     => $this->created_by,
            // Público → URL estable permanente (sin firma; apta para firmas de
            // correo, web, CDN). Privado → URL firmada de corta vida.
            'view_url'       => $this->tryFileUrl(fn () => $this->visibility === 'public' ? $this->viewUrl() : $this->signedViewUrl()),
            'download_url'   => $this->tryFileUrl(fn () => $this->visibility === 'public' ? $this->url() : $this->signedDownloadUrl()),
            'tags'           => $this->whenLoaded('tags', fn () => $this->tags->pluck('slug')),
        ];
    }

    /**
     * Generate a file URL, returning null if the route is not registered.
     * The serving routes (innertia.files.view / innertia.files.download) are
     * registered automatically by InnertiaServiceProvider but may be absent
     * in minimal test environments.
     */
    private function tryFileUrl(\Closure $fn): ?string
    {
        try {
            return $fn();
        } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException $e) {
            return null;
        }
    }
}
