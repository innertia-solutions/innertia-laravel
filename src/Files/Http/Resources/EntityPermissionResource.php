<?php

namespace Innertia\Files\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class EntityPermissionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'entity_type'    => $this->entity_type,
            'entity_id'      => $this->entity_id,
            'grantable_type' => $this->grantable_type,
            'grantable_id'   => $this->grantable_id,
            'action'         => $this->action,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
