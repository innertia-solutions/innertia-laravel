<?php

namespace Innertia\Tags\UseCases;

use Innertia\Tags\Exceptions\TagNotFoundException;
use Innertia\Tags\Models\Tag;

class DeleteTag
{
    public function __construct(
        public readonly string $tagId,
    ) {}

    public function execute(): void
    {
        $tag = Tag::find($this->tagId);

        if (! $tag) {
            throw TagNotFoundException::forId($this->tagId);
        }

        $id   = $tag->id;
        $slug = $tag->slug;

        $tag->delete(); // taggables cascadean por FK

        event(new \Innertia\Tags\Events\TagDeleted($id, $slug));
    }
}
