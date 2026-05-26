<?php

use Innertia\Tags\TagsFeature;

it('returns false when tags feature is disabled by default', function () {
    config()->set('innertia.tags.enabled', false);
    expect(TagsFeature::isActive())->toBeFalse();
});

it('returns true when tags feature is enabled', function () {
    config()->set('innertia.tags.enabled', true);
    expect(TagsFeature::isActive())->toBeTrue();
});

it('exposes the tag model class from config', function () {
    expect(TagsFeature::modelClass())->toBe(\Innertia\Tags\Models\Tag::class);
});
