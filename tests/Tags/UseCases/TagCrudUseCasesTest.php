<?php

use Innertia\Tags\Exceptions\DuplicateTagException;
use Innertia\Tags\Exceptions\TagNotFoundException;
use Innertia\Tags\Models\Tag;
use Innertia\Tags\UseCases\CreateTag;
use Innertia\Tags\UseCases\DeleteTag;
use Innertia\Tags\UseCases\UpdateTag;

beforeEach(function () {
    config()->set('innertia.tags.enabled', true);
    config()->set('innertia.mode', 'app');
    \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON');
    require_once __DIR__ . '/../helpers/migrate.php';
    innertiaTagsMigrateUp();
});

afterEach(fn () => innertiaTagsMigrateDown());

it('creates a tag', function () {
    $tag = (new CreateTag(name: 'Urgente', color: '#ff0000'))->execute();

    expect($tag->slug)->toBe('urgente');
    expect($tag->color)->toBe('#ff0000');
});

it('throws DuplicateTagException when slug already exists', function () {
    (new CreateTag(name: 'Urgente'))->execute();

    expect(fn () => (new CreateTag(name: 'URGENTE'))->execute())
        ->toThrow(DuplicateTagException::class);
});

it('updates a tag name and slug', function () {
    $tag = (new CreateTag(name: 'Urgente'))->execute();

    $updated = (new UpdateTag(tagId: $tag->id, name: 'Crítico', color: '#000000'))->execute();

    expect($updated->slug)->toBe('critico');
    expect($updated->color)->toBe('#000000');
});

it('throws TagNotFoundException when updating missing tag', function () {
    expect(fn () => (new UpdateTag(tagId: 'missing-uuid', name: 'X'))->execute())
        ->toThrow(TagNotFoundException::class);
});

it('deletes a tag and cascades to taggables', function () {
    $tag = (new CreateTag(name: 'Urgente'))->execute();

    \Illuminate\Support\Facades\DB::table('taggables')->insert([
        'tag_id' => $tag->id,
        'taggable_type' => 'App\\Quote',
        'taggable_id' => \Illuminate\Support\Str::uuid(),
        'tagged_at' => now(),
    ]);

    (new DeleteTag(tagId: $tag->id))->execute();

    expect(Tag::find($tag->id))->toBeNull();
    expect(\Illuminate\Support\Facades\DB::table('taggables')->count())->toBe(0);
});
