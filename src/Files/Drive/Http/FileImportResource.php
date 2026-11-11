<?php

namespace Innertia\Files\Drive\Http;

use Illuminate\Http\Resources\Json\JsonResource;

class FileImportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'kind'                => $this->kind?->value,
            'status'              => $this->status?->value,
            'provider'            => $this->provider,
            'target_directory_id' => $this->target_directory_id,
            'file_id'             => $this->file_id,
            'native_format'       => $this->native_format,
            'found'               => $this->found,
            'imported'            => $this->imported,
            'updated'             => $this->updated,
            'unchanged'           => $this->unchanged,
            'skipped'             => $this->skipped,
            'failed'              => $this->failed_count,
            'items'               => $this->items ?? [],
            'error'               => $this->error,
            'started_at'          => $this->started_at?->toIso8601String(),
            'finished_at'         => $this->finished_at?->toIso8601String(),
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
