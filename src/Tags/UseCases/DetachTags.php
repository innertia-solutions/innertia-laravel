<?php

namespace Innertia\Tags\UseCases;

use Illuminate\Database\Eloquent\Model;

class DetachTags
{
    public function __construct(
        public readonly Model $entity,
        public readonly array $names,
    ) {}

    public function execute(): void
    {
        $this->entity->untag($this->names);

        event(new \Innertia\Tags\Events\TagsDetached($this->entity, $this->names));
    }
}
