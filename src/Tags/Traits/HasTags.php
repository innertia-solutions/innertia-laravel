<?php

namespace Innertia\Tags\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Innertia\Tags\Models\Tag;
use Innertia\Tags\TagsFeature;

/**
 * Aplica al modelo del consumer para habilitar tagging.
 *
 *   use Innertia\Tags\Traits\HasTags;
 *
 *   class Quote extends Model {
 *       use HasTags;
 *   }
 *
 *   $quote->tag('urgente', 'vip');
 *   $quote->untag('vip');
 *   $quote->hasTag('urgente');
 *   Quote::withAnyTag(['vip', 'urgente'])->get();
 */
trait HasTags
{
    public function tags(): MorphToMany
    {
        $tagModel = TagsFeature::modelClass();

        return $this->morphToMany($tagModel, 'taggable', 'taggables', 'taggable_id', 'tag_id')
            ->withPivot(['tagged_by', 'tagged_at']);
    }

    // ── Mutators ──────────────────────────────────────────────────────────────

    /**
     * Attach tags by name (creates missing). Idempotent.
     *
     * @param string|array<string> $tags
     */
    public function tag(string|array ...$tags): static
    {
        if (! TagsFeature::isActive()) {
            throw \Innertia\Tags\Exceptions\FeatureDisabledException::tags();
        }

        $names = $this->flattenTagArgs($tags);
        if (empty($names)) {
            return $this;
        }

        $ids = collect($names)
            ->map(fn ($name) => Tag::findOrCreateByName($name)->id)
            ->unique()
            ->all();

        $this->tags()->syncWithoutDetaching(
            collect($ids)->mapWithKeys(fn ($id) => [$id => [
                'tagged_by' => auth()->id(),
                'tagged_at' => now(),
            ]])->all()
        );

        $this->unsetRelation('tags');

        return $this;
    }

    /**
     * Detach tags by name. Missing tags are ignored.
     *
     * @param string|array<string> $tags
     */
    public function untag(string|array ...$tags): static
    {
        if (! TagsFeature::isActive()) {
            throw \Innertia\Tags\Exceptions\FeatureDisabledException::tags();
        }

        $names = $this->flattenTagArgs($tags);
        if (empty($names)) {
            return $this;
        }

        $slugs = collect($names)->map(fn ($n) => Tag::slugify($n))->all();
        $ids   = Tag::query()->whereIn('slug', $slugs)->pluck('id')->all();

        if (! empty($ids)) {
            $this->tags()->detach($ids);
            $this->unsetRelation('tags');
        }

        return $this;
    }

    public function retag(string|array ...$tags): static
    {
        if (! TagsFeature::isActive()) {
            throw \Innertia\Tags\Exceptions\FeatureDisabledException::tags();
        }

        $this->clearTags();
        $this->tag(...$tags);
        return $this;
    }

    public function clearTags(): static
    {
        if (! TagsFeature::isActive()) {
            throw \Innertia\Tags\Exceptions\FeatureDisabledException::tags();
        }

        $this->tags()->detach();
        $this->unsetRelation('tags');
        return $this;
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function hasTag(string $name): bool
    {
        $slug = Tag::slugify($name);
        return $this->tags()->where('slug', $slug)->exists();
    }

    public function hasAnyTag(array $names): bool
    {
        $slugs = collect($names)->map(fn ($n) => Tag::slugify($n))->all();
        return $this->tags()->whereIn('slug', $slugs)->exists();
    }

    public function hasAllTags(array $names): bool
    {
        $slugs = collect($names)->map(fn ($n) => Tag::slugify($n))->all();
        $count = count($slugs);
        return $this->tags()->whereIn('slug', $slugs)->count() === $count;
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    public function scopeWithTag(Builder $q, string $name): Builder
    {
        $slug = Tag::slugify($name);
        return $q->whereHas('tags', fn ($t) => $t->where('slug', $slug));
    }

    public function scopeWithAnyTag(Builder $q, array $names): Builder
    {
        $slugs = collect($names)->map(fn ($n) => Tag::slugify($n))->all();
        return $q->whereHas('tags', fn ($t) => $t->whereIn('slug', $slugs));
    }

    public function scopeWithAllTags(Builder $q, array $names): Builder
    {
        $slugs = collect($names)->map(fn ($n) => Tag::slugify($n))->all();
        $count = count($slugs);
        return $q->whereHas(
            'tags',
            fn ($t) => $t->whereIn('slug', $slugs),
            '=',
            $count
        );
    }

    public function scopeWithoutTag(Builder $q, string $name): Builder
    {
        $slug = Tag::slugify($name);
        return $q->whereDoesntHave('tags', fn ($t) => $t->where('slug', $slug));
    }

    public function scopeWithoutAnyTag(Builder $q, array $names): Builder
    {
        $slugs = collect($names)->map(fn ($n) => Tag::slugify($n))->all();
        return $q->whereDoesntHave('tags', fn ($t) => $t->whereIn('slug', $slugs));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string|array<string>> $args */
    private function flattenTagArgs(array $args): array
    {
        $flat = [];
        array_walk_recursive($args, function ($item) use (&$flat) {
            if (is_string($item) && $item !== '') {
                $flat[] = $item;
            }
        });
        return array_values(array_unique($flat));
    }
}
