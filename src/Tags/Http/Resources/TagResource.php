<?php

namespace Innertia\Tags\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TagResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'color'           => $this->color,
            'created_by'      => $this->created_by,
            'created_at'      => $this->created_at?->toIso8601String(),
            'usage_count'     => $this->when(isset($this->taggables_count), $this->taggables_count),
        ];
    }
}
