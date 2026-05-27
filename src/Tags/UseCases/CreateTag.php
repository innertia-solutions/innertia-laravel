<?php

namespace Innertia\Tags\UseCases;

use Innertia\Tags\Exceptions\DuplicateTagException;
use Innertia\Tags\Models\Tag;

class CreateTag
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $color = null,
    ) {}

    public function execute(): Tag
    {
        $slug = Tag::slugify($this->name);

        if (Tag::query()->where('slug', $slug)->exists()) {
            throw DuplicateTagException::forSlug($slug);
        }

        $tag = Tag::create([
            'name'  => $this->name,
            'slug'  => $slug,
            'color' => $this->color,
        ]);

        event(new \Innertia\Tags\Events\TagCreated($tag));

        return $tag;
    }
}
