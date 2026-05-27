<?php

namespace Innertia\Tags\UseCases;

use Illuminate\Database\Eloquent\Model;

class SyncTags
{
    public function __construct(
        public readonly Model $entity,
        public readonly array $names,
    ) {}

    public function execute(): void
    {
        $existingSlugs = $this->entity->tags()->pluck('slug')->all();

        $this->entity->retag($this->names);

        $newSlugs = $this->entity->fresh()->tags()->pluck('slug')->all();
        $added    = array_values(array_diff($newSlugs, $existingSlugs));
        $removed  = array_values(array_diff($existingSlugs, $newSlugs));

        event(new \Innertia\Tags\Events\TagsSynced($this->entity, $added, $removed));
    }
}
