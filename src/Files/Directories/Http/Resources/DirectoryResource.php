<?php

namespace Innertia\Files\Directories\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DirectoryResource extends JsonResource
{
    /** Set to true for single-item responses (show, store, update). */
    public bool $withBreadcrumbs = false;

    public static function single($resource): self
    {
        $instance = new self($resource);
        $instance->withBreadcrumbs = true;

        return $instance;
    }

    public function toArray($request): array
    {
        $ownerTypes = array_flip(config('innertia.directories.owner_types', []));
        $ownerType  = $this->owner_type
            ? ($ownerTypes[$this->owner_type] ?? $this->owner_type)
            : null;

        $data = [
            'id'             => $this->id,
            'name'           => $this->name,
            'parent_id'      => $this->parent_id,
            'path'           => $this->path,
            'depth'          => $this->depth,
            'owner'          => $ownerType !== null
                ? ['type' => $ownerType, 'id' => $this->owner_id]
                : null,
            'trash_group_id' => $this->trash_group_id,
            'deleted_at'     => $this->deleted_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
            'created_by'     => $this->created_by,
        ];

        if ($this->withBreadcrumbs) {
            $data['breadcrumbs'] = $this->breadcrumbs();
        }

        if ($request->query('include') && str_contains($request->query('include'), 'children_count')) {
            $data['children_count'] = $this->children()->count();
        }

        return $data;
    }
}
