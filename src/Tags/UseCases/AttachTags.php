<?php

namespace Innertia\Tags\UseCases;

use Illuminate\Database\Eloquent\Model;

class AttachTags
{
    public function __construct(
        public readonly Model $entity,
        public readonly array $names,
    ) {}

    public function execute(): void
    {
        $this->entity->tag($this->names);
    }
}
