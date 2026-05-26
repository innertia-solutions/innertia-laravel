<?php

namespace Innertia\Tags\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Innertia\Platform\Traits\HasTenant;

/**
 * Tag — etiqueta tenant-scoped, plana, polimórfica.
 *
 * Slug es la clave única dentro del tenant. `findOrCreateByName($name)` es la
 * vía recomendada: slugifica internamente y reutiliza si existe.
 */
class Tag extends Model
{
    use HasUuids;
    use HasTenant;

    protected $table = 'tags';

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'color',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tag) {
            if (empty($tag->slug) && ! empty($tag->name)) {
                $tag->slug = static::slugify($tag->name);
            }

            if (empty($tag->created_by) && auth()->check()) {
                $tag->created_by = auth()->id();
            }
        });
    }

    // ── Static factories ──────────────────────────────────────────────────────

    public static function findOrCreateByName(string $name, ?string $color = null): self
    {
        $slug = static::slugify($name);

        $existing = static::query()->where('slug', $slug)->first();
        if ($existing) {
            return $existing;
        }

        return static::create([
            'name'  => $name,
            'slug'  => $slug,
            'color' => $color,
        ]);
    }

    public static function slugify(string $name): string
    {
        $generator = config('innertia.tags.slug_generator');

        if (is_callable($generator)) {
            return $generator($name);
        }

        return Str::slug($name);
    }

    // ── Query scopes ──────────────────────────────────────────────────────────

    public function scopePopular(Builder $q, int $limit = 20, ?string $taggableType = null): Builder
    {
        $q->withCount('taggables')
            ->orderByDesc('taggables_count')
            ->limit($limit);

        if ($taggableType !== null) {
            $q->whereHas('taggables', fn ($t) => $t->where('taggable_type', $taggableType));
        }

        return $q;
    }

    public function scopeForTaggable(Builder $q, string $modelClass): Builder
    {
        return $q->whereHas('taggables', fn ($t) => $t->where('taggable_type', $modelClass));
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function taggables()
    {
        return $this->hasMany(Taggable::class, 'tag_id');
    }
}
