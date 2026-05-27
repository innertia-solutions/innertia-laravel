<?php

namespace Innertia\Tags\UseCases;

use Innertia\Tags\Exceptions\DuplicateTagException;
use Innertia\Tags\Exceptions\TagNotFoundException;
use Innertia\Tags\Models\Tag;

class UpdateTag
{
    public function __construct(
        public readonly string $tagId,
        public readonly ?string $name = null,
        public readonly ?string $color = null,
    ) {}

    public function execute(): Tag
    {
        $tag = Tag::find($this->tagId);

        if (! $tag) {
            throw TagNotFoundException::forId($this->tagId);
        }

        $oldName  = $tag->name;
        $oldColor = $tag->color;

        if ($this->name !== null) {
            $newSlug = Tag::slugify($this->name);

            if ($newSlug !== $tag->slug
                && Tag::query()->where('slug', $newSlug)->where('id', '!=', $tag->id)->exists()) {
                throw DuplicateTagException::forSlug($newSlug);
            }

            $tag->name = $this->name;
            $tag->slug = $newSlug;
        }

        if ($this->color !== null) {
            $tag->color = $this->color;
        }

        $tag->save();

        $changes = [
            'old' => ['name' => $oldName, 'color' => $oldColor],
            'new' => ['name' => $tag->name, 'color' => $tag->color],
        ];

        event(new \Innertia\Tags\Events\TagUpdated($tag, $changes));

        return $tag;
    }
}
